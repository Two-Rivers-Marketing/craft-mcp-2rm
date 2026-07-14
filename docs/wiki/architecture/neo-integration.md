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

## Known limitation

- **`create_neo_block` returns `blockIds: [null, ...]`.** Because Neo creates fresh blocks from the serialized data, the plugin's pre-save `Block` objects never get ids assigned. Blocks are created correctly; only the id echo is missing. Fix would re-query the owner's blocks post-save and diff against the pre-save id set. Tracked in [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md).

## Cross-references

- [freeform-integration.md](freeform-integration.md) — sibling integration gotchas (same duck-typed-against-absent-plugin origin)
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../overview/project.md](../overview/project.md) — QA priority order
