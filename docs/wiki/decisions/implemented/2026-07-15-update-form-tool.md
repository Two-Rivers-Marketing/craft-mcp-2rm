# `update_form` v1 — add/remove/reorder fields on an existing single-page Freeform form (implemented)

**Status:** implemented (pending live verification)
**Date:** 2026-07-15
**Issue:** #20
**Supersedes the remaining `update_form` portion of:** [decisions/deferred/2026-07-14-freeform-write-tools.md](../deferred/2026-07-14-freeform-write-tools.md)

## Decision

Shipped the v1 `update_form` tool scoped in [plans/create-form-tool.md](../../plans/create-form-tool.md)
("Later" section): add, remove, or reorder fields on an **existing single-page**
form built from the `create_form` v1 field-type subset (`text`, `textarea`,
`email`, `dropdown`, `checkbox`, `number`). `fields` is the complete desired
field list (same shape as `create_form`); a spec matched by handle to a current
field is **kept** (reuses its identity), an unmatched spec **adds** a field, and
a currently-supported field whose handle is missing from `fields` is **removed**.
Out of scope: notifications, integrations, multi-page forms, conditional logic
— unchanged, matching `create_form`.

## The UID-preservation problem, and how it's solved

Reverse-engineered against live Freeform 5.15 (mbd, form id 3, "Contact Form")
via read-only `tinker`/`run_query` — see
[architecture/freeform-integration.md](../../architecture/freeform-integration.md)
("`update_form` — editing an existing form") for the full trace. Summary:

- A submission's field values live in a **per-form content table**
  (`freeform_submissions_<handle>_<formId>`) with one column per field, named
  `<handle>_<fieldId>` (e.g. `email_10`). The column is keyed by the field's
  **numeric database id**, not its handle or uid.
- `LayoutPersistence::handleLayoutSave()` (Freeform's own `EVENT_UPSERT_FORM`
  listener) diffs the payload's `fields`/`rows`/`pages`/`layouts` arrays against
  the form's current rows **by `uid`**: a uid present in both is updated in
  place (same underlying row id); a uid missing from the payload is deleted;
  a uid not currently in the DB is inserted as new.
- Reusing an existing field's **exact `uid`** in the payload is therefore what
  keeps its database id — and hence its content column — stable across a save.
  `FormContentTable` (a separate `EVENT_UPSERT_FORM` listener, priority 305)
  then syncs the content table: same field id → column kept (renamed only if
  the handle changed); id no longer present → column **dropped**; new id →
  column added. This all happens automatically once the update+upsert events
  fire — no code needed on our side beyond building the payload correctly.
- Implication: **add** = new uid (new column, empty for existing rows).
  **Remove** = uid omitted from the payload (column dropped, data lost — by
  design). **Reorder** = same uid, different `order`/row placement (no content
  impact at all). **Kept fields whose row is shared with another field**
  (CP-built side-by-side layout) reuse that row's `uid` too, preserving the
  grouping.
- **Safety net for CP-built forms:** fields whose stored type is outside the
  v1 subset (file upload, signature, group, table, etc.) are always passed
  through **unchanged** (`FreeformFormPlan::planFieldChanges()`'s `preserved`
  bucket) even if the caller's `fields` list omits them — the tool has no way
  to safely represent or rebuild an unsupported field type, so treating
  "omitted" as "remove" for those would silently destroy data it doesn't
  understand. If an unsupported field shares a row with a field the caller
  IS editing, the whole update is rejected (`conflicts`) rather than guessed.
- Also required: the payload's `form.settings` must carry **every** current
  settings namespace/property verbatim (read from `craft_freeform_forms.metadata`
  and passed through as decoded `stdClass`), because
  `FormPersistence::getValidatedMetadata()` resets any omitted property to its
  type default — `create_form` doesn't hit this (nothing to lose on a new
  form), but `update_form` would silently reset `behavior`-namespace settings
  (success message, redirect URL, etc.) on every edit without this.

## Implementation

- **`src/tools/FreeformScaffoldTools.php`** — new `update_form` method
  alongside `create_form` (same class, same conditional/dangerous pattern).
  Reads the form's current pages/layouts/rows/fields via `craft\db\Query`
  against Freeform's own tables (no `Solspace\Freeform\*` import — a lighter
  duck-typed surface than resolving Freeform's Record classes), asserts
  single-page, diffs via `FreeformFormPlan::planFieldChanges()`, asserts no
  row conflicts, then (unless `dryRun`) builds the update payload and
  persists via `EVENT_UPDATE_FORM` + `EVENT_UPSERT_FORM` (refactored
  `persistForm`/`persistFormUpdate` to share a `triggerPersist()` helper).
  Reuses `runAsAdmin()` (update dereferences the user identity too) and
  `flushFormCache()` unchanged from `create_form`.
- **`src/support/FreeformFormPlan.php`** — added `resolveExistingType()`
  (typeClass → v1 keyword, null if unsupported) and `planFieldChanges()` (the
  pure kept/added/removed/preserved/conflicts diff, including row/field order
  assignment). Stays Craft-boot-free: it never generates UUIDs itself — the
  tool assigns real `StringHelper::UUID()` values for anything the plan marks
  `isNew`.
- `dryRun: true` returns an old → new diff (`kept`/`added`/`removed`/
  `untouched`/`order`) built from the same plan, before anything is persisted.

## Tests

Boot-free, mirroring `create_form`'s patterns: structural reflection tests
extended in `tests/Unit/Tools/FreeformScaffoldToolsTest.php` (tool count → 2,
new `update_form` describe block) + pure-logic tests added to
`tests/Unit/Support/FreeformFormPlanTest.php` covering `resolveExistingType`
and `planFieldChanges` (new-form-equivalent, kept+reused-uid, reorder, add,
remove, shared-row grouping, unsupported-field preservation + row offset,
and both conflict/no-conflict shared-row cases). 15 new tests; full suite
472 pass, phpstan clean (`--memory-limit=1G`), pint clean.

The tool was **not** live-verified — it isn't deployed to the running MCP
server (`create_form` from #19 isn't either yet; both need a human SIGHUP
restart), so add/remove/reorder + post-edit submission read were verified
by design/tracing, not by driving the tool end-to-end. Live verification is
handed off on issue #20.

## Not relitigated

Notification/element-connection config and multi-page/conditional logic
remain future work (see the plan's "Later" section and the backlog). Editing
a form with more than one page, or a field sharing a row with an unsupported
field type, is rejected rather than supported.
