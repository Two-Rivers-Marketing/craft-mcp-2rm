---
type: Convention
title: MCP Content Editing
description: How to read, create, and update CMS content through the MCP tools — tool selection, Neo block writes and their payload rules, and the update_entry limitation.
tags: [mcp, content-editing, neo, entries, tinker, api]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# MCP Content Editing

## Reading content

Use `get_entry` to read a single entry by ID or slug, and `list_entries` to browse a section. For anything touching the content builder (Neo field), call `describe_content_builder` **first, before any content-builder work** — it returns every block type with its fields, valid option values, nesting rules, and whether a matching `body_blocks/` template exists. Skipping it produces writes against a guessed schema instead of the real one. `get_block_type` inspects a single block type in full depth when `describe_content_builder`'s summary isn't enough.

## Tool selection for writes

`update_entry` writes flat fields — title, slug, custom scalar fields — and is the right tool for those. It **cannot write Neo blocks**: any content-builder change goes through a Neo tool or the Craft API. Reach for `update_entry` only when nothing in the change touches the Neo tree.

For Neo content:

- `create_neo_block` adds a block or subtree, nested Matrix included, at a position or under `parentBlockId`.
- `update_neo_block` changes one block's fields.
- One `setFieldValue` + `saveElement($entry)` with the whole tree (via `tinker`) suits bulk work, new pages and whole-tree replacement.
- `reorder_neo_blocks` and `delete_neo_block` handle structure.

## Neo block write recipe

All four write paths — `create_neo_block`, `update_neo_block`, a one-save `setFieldValue` + `saveElement` of the whole tree, and per-block saves — persist nested Matrix data when the payload is well-shaped. Verified on craft-mcp v1.8.0, Neo 5.5.13, Craft 5.11.2: a 12-block tree with 11 nested Matrix entries wrote 11/11 on every path, including `create_neo_block` into an existing parent. No second "nested pass" is needed. (An earlier version of this page said `create_neo_block` silently drops nested Matrix data. That does not reproduce; the cause was a malformed payload, not the tool.)

The real hazards:

- **`update_neo_block` replaces values wholesale.** A Table value is the whole row: sending one cell nulls the other cells. A Matrix list replaces every entry (new IDs, old ones deleted). An ID-keyed Matrix map updates in place but deletes any existing entry it leaves out.
- **Five malformed shapes fail silently.** The save returns `success: true` but persists nothing: a Matrix `entries` map without `sortOrder`; a single entry object not wrapped in a list; sub-field values outside `fields`; a wrong entry-type handle; a Neo `blocks` value without `sortOrder`. See [#39](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/issues/39).
- **`get_entry` returns block IDs only** (first 10 of N). It cannot verify nested data. See [#40](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/issues/40).

### Rule for every Neo write

(a) **Matrix shape.** A nested Matrix value is always a JSON list of `{"type": <entryTypeHandle>, "fields": {…}}` entries, each with `sortOrder` (or `{"entries": {…}, "sortOrder": […]}`). Neo block values also carry `sortOrder`, listing existing block IDs plus `newN`. Take entry-type handles from `describe_content_builder` or `get_block_type`.

(b) **Updates are complete sets.** Send the complete Table row and every Matrix entry that should be kept (keyed by existing ID), because `update_neo_block` replaces values in full.

(c) **New blocks.** Leave a Table field out so it gets its defaults, or send the full row. Never send a partial row: tables are stored verbatim and unsent cells stay `null`, which loses padding and container classes on the front end.

(d) **Read back.** After every write, re-read the touched blocks with a script or `tinker` and compare entry counts and values to the payload. `get_entry` is not enough.

The checkable form of these rules is CBS-49 to CBS-55 in `content-builder-standards.md`.

## Tinker constraints

- The MCP enforces a **120s timeout on the tool call**, but the underlying PHP keeps running server-side past that — a timeout doesn't mean the write failed, check the result before retrying.
- **Batch fewer than 50 saves per call.** Larger batches risk hitting the timeout or memory limits; split bulk operations into multiple tinker calls instead of one giant loop.
- The sandbox blocks filesystem-write primitives like `copy()` and `file_put_contents()`, but **Craft's own internals (element saves, asset handling) are unaffected** — the restriction is on raw PHP file I/O, not the framework.
- `run_query` is **SELECT-only** — use it for reads; any write goes through `tinker` and the element API, never raw SQL.

## Creating entries

For a new entry with only flat fields, `create_entry` is sufficient. When the entry also needs Neo content, either `create_entry` first (for the flat fields) followed by `tinker` for the content-builder tree, or skip straight to `tinker` for the whole entry when Neo content is the primary payload — both routes end at a Neo write that follows the rule above.
