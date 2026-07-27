---
type: decision
timestamp: 2026-07-23
title: delete_form v1 — guarded form delete with full cascade cleanup
status: implemented
---

# `delete_form` v1 — guarded form delete with full cascade cleanup

**Status:** implemented
**Date:** 2026-07-23
**Scope:** the `delete_form` MCP tool (`FreeformScaffoldTools`), completing the Freeform write CRUD surface
**Supersedes:** —

## Decision

Ship `delete_form` as the third Freeform scaffold tool. It matches a form by id or handle, previews via `dryRun`, requires a **confirm-by-handle** argument (a bare call never deletes), and is marked `dangerous: true`. It reuses Freeform's own `deleteById()` then cleans the structural orphans that call leaves behind, and flushes the in-process read caches so the deletion is visible same-session.

## Rationale

`create_form` (#19) and `update_form` (#20) shipped without a delete counterpart. Live-verify (#31 discovery) proved a naive `Freeform::forms->deleteById()` is not enough on its own in this environment for two reasons rooted in the same long-running-server realities the rest of the Freeform surface fights:

- It trips the #29 `SubmissionQuery` stale-static crash for a same-session form → guarded via `FreeformStaleFormCache::guard`.
- It leaves orphans across **8 tables** (`freeform_forms_fields/_rows/_pages/_layouts`, `freeform_submissions` meta, and the submission elements' `searchindex`/`elements_sites`/`elements`) — confirmed empirically live. `delete_form` cleans them FK-safe and asserts 0 remain (idempotent, so safe whether or not DB CASCADE FKs are live). Logic in `src/support/FreeformFormDeletionCascade.php`.
- Same-session `list_forms`/`get_form` kept returning the deleted form until the read caches were flushed → calls `FreeformFormCacheReset::reset()` on the delete path.

Data-loss risk (deleting a form drops its submissions) is why it carries `dangerous: true` + the confirm-by-handle gate + `dryRun`.

## Cross-references

- [architecture/freeform-integration.md](../../architecture/freeform-integration.md#delete_form--deleting-a-form--its-full-cascade-2026-07-23-31) — the cascade + guard detail
- [decisions/implemented/2026-07-15-create-form-tool.md](2026-07-15-create-form-tool.md), [decisions/implemented/2026-07-15-update-form-tool.md](2026-07-15-update-form-tool.md) — the other two CRUD tools
- [decisions/deferred/2026-07-14-freeform-write-tools.md](../deferred/2026-07-14-freeform-write-tools.md) — the parent incremental-build deferral

## Do not relitigate without

A live-verify failure of the delete cascade/guard, or a decision to expose form deletion under different safety semantics (e.g. soft-delete/trash instead of hard delete). Live-verify is still pending — see `docs/live-verify-handoff-2026-07-22.md`.
