---
type: Convention
title: MCP Content Editing
description: How to read, create, and update CMS content through the MCP tools — tool selection, Neo block writes, and the update_entry limitation.
tags: [mcp, content-editing, neo, entries, tinker, api]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# MCP Content Editing

## Reading content

Use `get_entry` to read a single entry by ID or slug, and `list_entries` to browse a section. For anything touching the content builder (Neo field), call `describe_content_builder` **first, before any content-builder work** — it returns every block type with its fields, valid option values, nesting rules, and whether a matching `body_blocks/` template exists. Skipping it produces writes against a guessed schema instead of the real one. `get_block_type` inspects a single block type in full depth when `describe_content_builder`'s summary isn't enough.

## Tool selection for writes

`update_entry` writes flat fields — title, slug, custom scalar fields — and is the right tool for those. It **cannot write Neo blocks**: any content-builder change (adding, editing, or restructuring blocks) has to go through `tinker` against the Craft API instead. Reach for `update_entry` only when nothing in the change touches the Neo tree; the moment a Neo block is involved, switch to the tinker recipe below for the whole operation, not just the Neo part.

## Neo block write recipe

The single-call pattern: `$entry->setFieldValue('contentBuilder', [...])` with the whole block tree — including nested Matrix fields like `image` or `backgroundProperties` — followed by one `Craft::$app->elements->saveElement($entry)`. This persists the entire tree, nested Matrix included, in one save. The MCP's two-step `create_neo_block` tool creates Neo nesting fine but **silently drops nested Matrix field data** (it writes 0 entries); it's fine for a handful of blocks added interactively, but not for bulk or anything with a Matrix field, where the one-save tinker path is the only reliable route.

## Tinker constraints

- The MCP enforces a **120s timeout on the tool call**, but the underlying PHP keeps running server-side past that — a timeout doesn't mean the write failed, check the result before retrying.
- **Batch fewer than 50 saves per call.** Larger batches risk hitting the timeout or memory limits; split bulk operations into multiple tinker calls instead of one giant loop.
- The sandbox blocks filesystem-write primitives like `copy()` and `file_put_contents()`, but **Craft's own internals (element saves, asset handling) are unaffected** — the restriction is on raw PHP file I/O, not the framework.
- `run_query` is **SELECT-only** — use it for reads; any write goes through `tinker` and the element API, never raw SQL.

## Creating entries

For a new entry with only flat fields, `create_entry` is sufficient. When the entry also needs Neo content, either `create_entry` first (for the flat fields) followed by `tinker` for the content-builder tree, or skip straight to `tinker` for the whole entry when Neo content is the primary payload — both routes end at the same one-save Neo write described above.
