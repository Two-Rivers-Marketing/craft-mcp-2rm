# Freeform write tools — build incrementally during QA, form-creation deferred

**Status:** deferred
**Date:** 2026-07-14
**Scope:** Freeform tool surface — specifically write-side tools (form creation/editing) beyond the existing submission delete/export.

## Decision

Defer building a Freeform `create_form` (and later `update_form`) tool. When built, start with a **minimal single-page form** and expand only as real use cases demand — do not attempt CP form-builder parity up front. More broadly: features surfaced during the live-QA pass are tracked in [plans/qa-feature-backlog.md](../../plans/qa-feature-backlog.md) and built as we go, not batched.

## Rationale

- Freeform 5 form creation is a large, version-fragile effort: a form is a layout tree (pages/rows/fields) plus notifications, integrations, and settings across several tables, none of it a stable public API. The read side already proved this surface is fragile (see [architecture/freeform-integration.md](../../architecture/freeform-integration.md)).
- Current QA priority is verifying and fixing the **existing** write surface (Neo trees, scaffolding, assets), not adding new scope.
- Incremental delivery mirrors how the Neo write tools grew successfully (flat → nested → scaffold).

## Cross-references

- [plans/create-form-tool.md](../../plans/create-form-tool.md) — the scope for v1
- [plans/qa-feature-backlog.md](../../plans/qa-feature-backlog.md)
- [architecture/freeform-integration.md](../../architecture/freeform-integration.md)

## Do not relitigate without

A concrete use case requiring programmatic form creation, OR completion of the higher-priority QA items (Neo tree writes, scaffolding, assets). Revisit then and build the v1 scope in `create-form-tool.md`.
