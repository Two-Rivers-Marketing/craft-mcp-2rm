# Decisions — Index

Locked, deferred, implemented, and superseded decisions. Read this at session start.

## Active

| Decision | Date | Scope |
| --- | --- | --- |
| [3.0 drops the plugin's own MCP server and registers into Craft's](active/2026-11-12-register-into-craft-native-mcp.md) | 2026-11-12 | 3.0 architecture, `trm_` tool prefix, user-permission access model, 2.x security-fix-only |

## Deferred

| Decision | Date | Revisit trigger |
| --- | --- | --- |
| [Freeform write tools — build incrementally, form-creation deferred](deferred/2026-07-14-freeform-write-tools.md) | 2026-07-14 | `create_form` and `update_form` v1 now implemented (below); remaining deferral covers notification/integration config, multi-page, conditional logic |

## Implemented

| Decision | Date | Notes |
| --- | --- | --- |
| [`create_form` v1 — minimal single-page Freeform form creator](implemented/2026-07-15-create-form-tool.md) | 2026-07-15 | Issue #19. Pending live verification. |
| [`update_form` v1 — add/remove/reorder fields on an existing single-page form](implemented/2026-07-15-update-form-tool.md) | 2026-07-15 | Issue #20. Pending live verification. |
| [`delete_form` v1 — guarded form delete with full cascade cleanup](implemented/2026-07-23-delete-form-tool.md) | 2026-07-23 | Issue #31. Completes Freeform write CRUD. Pending live verification. |

## Superseded

_(none yet)_
