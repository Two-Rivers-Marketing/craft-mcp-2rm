# Neo integration — the write path (spicyweb/craft-neo 5.5)

How the Neo write tools persist blocks, and the bug that broke all of them against real Neo (2026-07-14 live-QA, item 2). The installed plugin is **spicyweb/craft-neo** (the maintained successor to benf/craft-neo) — but the PHP namespace is still `benf\neo\*`, so the plugin's `benf\neo\elements\Block` references are correct.

## The bug: passing Block objects to setFieldValue

All four write tools (`create_neo_block`, `update_neo_block`, `reorder_neo_blocks`, `delete_neo_block`) funnel through `NeoContentTools::persistBlocks()`. It originally did:

```php
$owner->setFieldValue($handle, $blocks); // $blocks = array of benf\neo\elements\Block objects
$owner->saveElement($owner);
```

Against real Neo 5.5 this throws on save:

```
TypeError: Cannot access offset of type benf\neo\models\BlockType in isset or empty (Field.php:1712)
```

**Why:** Neo's `Field::normalizeValue()` treats an array value as **serialized block data**, not element objects. It calls `_createBlocksFromSerializedData()`, which for each entry evaluates `$blockData['type']`. Given a `Block` element (ArrayAccess), `$block['type']` returns the `BlockType` **object**, which Neo then uses as an array key (`$blockTypes[$blockData['type']]`) → TypeError. Dry-runs never hit this because they never call Neo.

## The fix: build Neo's serialized delta format

`persistBlocks()` now serializes the ordered blocks via `toNeoValue()` into the shape Neo actually consumes (mirrors what `Field::serializeValue()` emits):

```php
[
  'blocks' => [
    '<id|newN>' => ['type' => <handle>, 'enabled' => bool, 'level' => int, 'fields' => <serializedFieldValues>],
    ...
  ],
  'sortOrder' => ['<id|newN>', ...],  // preorder
]
```

- Existing blocks keyed by their real **id** → Neo updates them in place.
- New blocks (no id) keyed `new1`, `new2`, … → Neo creates them.
- `level` drives nesting; Neo rebuilds `lft`/`rgt` from the ordered list + levels.

Verified live end-to-end on a scratch entry: create (3-level multiColumn→columnItem→plainText tree) → update a leaf → reorder top-level trees → delete a subtree. After all four, nested-set integrity held (`lft`/`rgt` contiguous, no gaps/dupes), edits applied, deletes cascaded.

Neo's `normalizeValue` also accepts a plain ordered array of the same per-block dicts (auto-keyed `newN`), but that recreates *everything* as new — unusable for update/reorder/delete which must preserve existing ids. The delta format is the correct general form.

## create_block_type (scaffolding, live-QA item 3)

Two bugs, both fixed in `src/tools/NeoScaffoldTools.php`; the block-type persistence itself was correct.

1. **Handle casing.** Handles were derived with `StringHelper::toCamelCase()`, which mangles acronyms — `"FAQ Section"` → `fAQSection`, `"QA Choice"` → `qAChoice`. Switched to `StringHelper::toHandle()` (Craft's canonical generator, what the CP uses) → `faqSection`, `qaChoice`. Normal multi-word names were unaffected; only acronym/all-caps names bit. Applies to both the block-type handle and new-field handles.

2. **Stale block-type memo in the long-running server.** After a successful save, a freshly created block type was invisible to follow-up tool calls (`get_block_type`, `describe_content_builder`, `create_neo_block`) — `get_block_type` reported "not found" even though the DB row, `neo.blockTypes` config, and `neo.orders` were all correct. Cause: Neo caches block types per field in a **process-static** `benf\neo\helpers\Memoize::$blockTypesByFieldId`, designed to live one web request. In the long-running MCP server that static persists across tool calls, and Neo's `save()` doesn't clear it. Fixed by `flushBlockTypeCaches()` after save (clear the Memoize static + `refreshFields()`). Note: `getCache()->flush()` and `refreshFields()` alone do **not** fix it — only clearing the Memoize static does. This is a general hazard for any tool that mutates Neo schema in the persistent server.

### Real ID echo (resolved 2026-07-15, #23)

Because Neo creates fresh records from the serialized/project-config data, the plugin's pre-save objects never get ids — so `create_neo_block` used to echo `blockIds: [null, ...]` and `create_block_type` echoed `blockType.id: null`. Both now re-read post-save:

- **`create_neo_block`** re-fetches the owner element after `persistBlocks()` (a *fresh* `getElementById` — the just-saved element memoizes its field value) and diffs the re-read block list against the pre-save summaries (`NeoBlockTree::newBlockIds()`). Ids not present pre-save are the new blocks, reported in document (lft) order — robust to any insertion position. Echo-only: failure degrades to `blockIds: []`, never fails the persisted create.
- **`create_block_type`** resolves the new type by handle via Neo's blockTypes service after `flushBlockTypeCaches()` (the flush means the read hits the DB, not the stale Memoize static). Echo-only: failure degrades to `id: null`.

### Known limitations (backlog)

- ~~**`create_block_type` stub hardcodes a `columnItem` children loop** regardless of the actual `childBlockTypes`.~~ **Resolved (#24):** `BlockTypeStub` now honors the declared child types — `columnItem` children keep the columnItem-include loop; other child types dispatch to their module partial via `columnItemPaths` (a single declared type is included by name, a mix dispatches on `item.type.handle`). Still a dev-editable scaffold.

## Long-running-server cache hazards (audit, #26)

The MCP server (`bin/mcp-server`) is one long-running PHP process, so any
process-static memo / service-singleton cache that a normal single-request web
lifecycle would discard **persists across tool calls**. A mutation in call A can
leave a read in call B stale. Full audit of every schema-/content-mutating tool
and its post-mutation read path:

| Tool | Read path after mutate | Persistent cache in play | Verdict |
|---|---|---|---|
| `create_block_type` (NeoScaffoldTools) | `get_block_type`, `describe_content_builder`, `create_neo_block` enumerate the field's block types | Neo `Memoize::$blockTypesByFieldId` (process-static) | **BUSTED** — `flushBlockTypeCaches()` clears the static + `refreshFields()` after save. Other `Memoize` statics (`$blockTypesById/ByHandle`, `$blockTypeGroups*`, `$parentFieldInstancesByLayoutElementUuid`) are keyed by the type's own id/handle/UUID, so a *fresh* create has no stale entry there — clearing `$blockTypesByFieldId` is sufficient. |
| `create_form` (FreeformScaffoldTools) | `get_form` (by handle), `list_forms` | Freeform `FormsService` singleton's private `Memo $cache` (`by-handle.*`, `all-forms.*`) | **WAS BROKEN → FIXED (#26).** `assertHandleAvailable()`'s pre-save `getFormByHandle(newHandle)` caches a `null` under that handle (Memo caches nulls via `array_key_exists`), and any earlier `list_forms` freezes the all-forms list. Neither is cleared by the create path. Fix: `flushFormCache()` clears the Memo (reflection; no public clear) after persist. Reproduced live (see #26). |
| `create_neo_block` / `update_neo_block` / `reorder_neo_blocks` / `delete_neo_block` (NeoContentTools) | `get_entry`, `describe_content_builder`, re-read of block ids | none stale — Neo `Memoize` caches block **types** (schema), not per-owner block **content**; block content is read from the elements table each call | **SAFE.** Content-only writes never touch the schema statics, and `create_neo_block` already re-reads a *fresh* `getElementById` for real ids (#23). Craft's element service returns a fresh element per `getElementById`, so no stale field-value memo survives. |
| `create_entry` / `update_entry` (EntryTools) | `get_entry`, `list_entries` | none — no schema mutation; element saved and read via fresh element queries | **SAFE.** Echoes from the just-saved element; no memoized service holds stale content. |
| `upload_asset` (AssetTools) | response serializes the saved Asset directly; `list_assets` / `list_asset_folders` re-query | Craft `Assets` service folder cache (only for created subfolders) | **SAFE.** Asset data comes from the freshly-saved element; folder listings run `findFolders` (DB) each call, not the memo. |
| `delete_submission` (FreeformTools) | `list_submissions`, `get_submission` | `FormsService` Memo caches **forms**, not submissions; counts computed via fresh query | **SAFE.** Submission element deleted via Craft's element service; reads are fresh element queries. |

Lesson carried from create_block_type: prefer a **surgical static/memo clear** over
a broad `getCache()->flush()` — for the Neo memo, `flush()` + `refreshFields()` did
**not** fix the stale read; only clearing the static did. The Freeform fix follows the
same shape (clear the service's in-memory Memo, not Craft's cache).

## Template resolution gotcha (get_block_type, describe_content_builder)

`get_block_type` reports `template.exists` by checking a single hardcoded path (`body_blocks/<handle>.twig`). This is wrong in two ways, both surfaced during a real KCMA build (2026-07-28, v1.4.0):

1. **Child block types use a different path.** Types with `topLevel: false` (e.g. `entryCard` under `columnItem`) render from `_includes/columnItems/<handle>.twig`, not `body_blocks/`. The reported path does not exist and never would for a child type.

2. **Craft template roots are invisible.** Plugins like `site-toolkit` register additional template roots via `Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, ...)`. A block type with no local template still renders from the plugin's copy via Craft's two-element fallback array. `exists: false` conflates "no template anywhere" with "renders fine from another root."

`describe_content_builder` inherits this — on a stock `site-toolkit` install, it reports `exists: false` for every block type, reading as "nothing renders" when 11 of 13 types do.

**Impact:** A prior KCMA session treated `exists: false` as evidence that types would not render and drew the wrong conclusion three times; the project handoff carries a dedicated warning.

**Fix path:** Resolve through Craft's template loader (`Craft::$app->getView()->resolveTemplate()`) across all registered roots, pick path by nesting level, report which root serves it (`servedBy: "site-toolkit"`). Verify against kcma.ddev.site.

Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md).

## Cross-references

- [freeform-integration.md](freeform-integration.md) — sibling integration gotchas (same duck-typed-against-absent-plugin origin); create_form cache fix detailed there
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../overview/project.md](../overview/project.md) — QA priority order
- [../raw/2026-07-28-kcma-build-tool-findings.md](../raw/2026-07-28-kcma-build-tool-findings.md) — source field report
