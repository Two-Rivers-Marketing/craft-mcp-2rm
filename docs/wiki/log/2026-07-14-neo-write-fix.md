# 2026-07-14 — Neo write suite fix (live-QA item 2)

**Operation:** ingest (architecture + backlog)
**Scope:** architecture/neo-integration.md, plans/qa-feature-backlog.md

## What happened

Live-QA item 2 (Neo multi-level tree writes). All four Neo write tools crashed on save against real spicyweb/craft-neo 5.5 with a `TypeError` at Field.php:1712 — `persistBlocks` handed Neo `Block` element objects, but Neo's `normalizeValue` expects serialized delta data (`{blocks: {id|newN: {type,enabled,level,fields}}, sortOrder}`).

Fixed by rewriting `persistBlocks` to serialize via a new `toNeoValue()` helper (`src/tools/NeoContentTools.php`). Verified live end-to-end on a scratch page (create 3-level tree → update leaf → reorder top-level → delete subtree); nested-set `lft`/`rgt` integrity held throughout. Scratch entry hard-deleted after.

Plugin suite green (154 Neo tests, phpstan --memory-limit=1G, pint). New architecture page documents the bug + delta format.

## Follow-ups

- `create_neo_block` returns `blockIds: [null,...]` (Neo creates fresh blocks from data; pre-save objects have no id). Low priority; logged in backlog.

## Next

Item 3: `create_block_type` scaffolding.
