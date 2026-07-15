# 2026-07-14 — create_block_type fixes (live-QA item 3)

**Operation:** ingest (architecture + backlog + overview)
**Scope:** architecture/neo-integration.md, plans/qa-feature-backlog.md, overview/project.md

## What happened

Live-QA item 3 (`create_block_type`). Two bugs found and fixed in `src/tools/NeoScaffoldTools.php`:

1. **Handle casing** — `StringHelper::toCamelCase()` mangled acronyms (`"FAQ Section"` → `fAQSection`). Switched to `toHandle()`. Verified live via dryRun.
2. **Stale block-type memo** — a real create left Neo's process-static `Memoize::$blockTypesByFieldId` unclear, so the new block type was invisible to follow-up MCP calls (`get_block_type` returned "not found") until a server restart — even though DB + project config were fully correct. Fixed with `flushBlockTypeCaches()` after save. Harmless in normal per-request web; a genuine long-running-server hazard.

A real block type (`qaScratchBlock` + fields `qaHeading`/`qaBody` + stub) was created on mbd and **verified in the CP by the user — came through perfectly**. Left in place at the user's request (mbd carries the resulting `config/project/*` + stub file changes).

The user's failed-jobs screenshot was `modules\snapback\jobs\SnapbackJob` (mbd's own module failing to write screenshots) — unrelated to craft-mcp; noted in backlog.

Plugin suite green (421 + Neo scaffold), phpstan --memory-limit=1G, pint clean.

## Next

Item 4: `upload_asset` (GCS-backed volume).
