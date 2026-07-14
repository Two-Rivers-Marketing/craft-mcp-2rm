# Plan: `create_form` — Freeform form creation tool

**Status:** proposed (deferred — see [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md))
**Raised:** 2026-07-14, during Freeform live-QA
**Motivation:** The Freeform surface is read + submission-management only. There is no way to create (or edit) a form via MCP, unlike Neo which has `create_block_type` / `create_neo_block`.

## Problem

Freeform 5 forms are not a flat object. A form is a **layout tree** — pages → rows → a field layout of field objects — plus notification config, integrations/element-connections, and spam/settings, persisted across several tables. None of the write side is a stable public PHP API (same reason `get_form`'s notifications/connections sections are hard to read — see [architecture/freeform-integration.md](../architecture/freeform-integration.md)). So full parity with the CP form builder is a large, version-fragile effort.

## Recommended scope: start minimal

Ship a **minimal single-page form creator** first; expand only as real use cases demand. This mirrors how the Neo write tools grew (flat block → nested trees → scaffolding).

### v1 (minimal) — `create_form`

- **Inputs:** `name`, optional `handle` (default camelCase of name), `fields` (JSON array of `{label, handle?, type, required?}`), `dryRun`.
- **Field types (v1):** `text`, `textarea`, `email`, `dropdown` (+`options`), `checkbox`, `number` — the common subset, mapped to `Solspace\Freeform\Fields\Implementations\*`.
- **Layout:** one page, one column, fields in given order.
- **Skip in v1:** notifications, integrations/element-connections, spam settings, multi-page, conditional rules, submit-button customization. Document these as not-configured (form gets Freeform defaults).
- **Pattern:** conditional tool (mirror existing `FreeformTools::isAvailable()`); `dangerous: true`; `dryRun` returns the planned form/layout/fields without saving (mirror `create_block_type`). All Freeform access duck-typed / lazily-resolved FQCNs so the class loads without Freeform present. phpstan ignore `#Solspace\\Freeform\\#`.
- **Verify:** live-QA against mbd (Freeform 5.15) — the creation API is unverified; needs the same live probing the read tools got.

### Later (only if needed)

- `update_form` (add/remove/reorder fields).
- Notification + element-connection config (depends on first nailing how to *read* them — currently broken; see backlog).
- Multi-page / conditional logic.

## Open questions (resolve at build time, against a live Freeform)

- What is the actual programmatic creation entry point in Freeform 5.15 — a `FormsService::create`-style call, a `Form` model save, or DB-layer builder? (Probe live, as with the read tools.)
- Do new fields need to exist in a field library first, or are they created inline in the layout?
- How is the layout tree (pages/rows) represented when saving?

## Cross-references

- [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md) — the deferral decision
- [architecture/freeform-integration.md](../architecture/freeform-integration.md) — verified 5.15 read API + why the write side is hard
- [qa-feature-backlog.md](qa-feature-backlog.md)
