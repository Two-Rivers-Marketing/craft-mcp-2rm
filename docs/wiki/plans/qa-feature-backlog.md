# QA-driven feature backlog

Running list of features, fixes, and gaps surfaced during the live-QA pass (see [overview/project.md](../overview/project.md)). New items get appended as QA proceeds through the priority list. When an item is committed to, promote it to a `decisions/deferred/` entry and (if it needs design) a `plans/` page. When shipped, move the decision to `decisions/implemented/`.

Legend: **P** = priority (relative), status = `open` / `scoped` / `in-progress` / `done`.

## Freeform

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| `create_form` write tool | med | scoped | No form creation exists. Scoped in [create-form-tool.md](create-form-tool.md); deferred per [decision](../decisions/deferred/2026-07-14-freeform-write-tools.md). Start minimal (single-page). |
| `get_form` notifications/connections/spamSettings return null | high | open | Keyword-dump approach misses Freeform 5's real structure; defeats "why didn't this submission create an entry". Needs real service reads. See [architecture/freeform-integration.md](../architecture/freeform-integration.md). |
| `update_form` (edit fields/layout) | low | open | Depends on `create_form` landing first. |
| `list_forms` submissionCount as JSON string elsewhere | low | open | `Serializer.php:101`, `AssetTools.php:75` return counts as strings — cosmetic consistency, not a crash. Cast to int if we care. |

## Neo

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Neo write suite crashed on save (all 4 tools) | high | **done** | `persistBlocks` passed Block objects; Neo wants serialized delta format. Fixed via `toNeoValue()`. Verified live (create/update/reorder/delete + nested-set integrity). See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| `create_neo_block` returns `blockIds: [null,...]` | low | open | Neo creates fresh blocks from serialized data, so pre-save objects have no id. Re-query owner blocks post-save and diff vs pre-save id set to report real ids. |
| Item 3: `create_block_type` scaffolding | — | not started | Next QA item (block-type service save, field-layout persistence, template stub). |
| Item 5: childBlocks / positioning edge cases | — | not started | `before:`/`after:`, `parentBlockId` nesting, childBlocks permission shape. |

## Assets

_(none yet — asset upload is item 4)_

## Cross-cutting / infra

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Orphan `stimmt` MCP server processes | low | open | Two zombie `vendor/stimmt/craft-mcp/bin/mcp-server` procs (PIDs 2220/17996 as of 2026-07-14) from the pre-rename path; safe to kill. Leftover from other MCP clients. |
| Reload ergonomics | low | open | Code changes need a manual SIGHUP restart of the long-running server; `reload_mcp` only detects new plugins. A smoother dev-reload would speed QA. |

## Cross-references

- [overview/project.md](../overview/project.md) — QA priority order
- [create-form-tool.md](create-form-tool.md)
- [architecture/freeform-integration.md](../architecture/freeform-integration.md)
