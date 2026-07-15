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

## Cross-references

- [freeform-integration.md](freeform-integration.md) — sibling integration gotchas (same duck-typed-against-absent-plugin origin)
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../overview/project.md](../overview/project.md) — QA priority order
