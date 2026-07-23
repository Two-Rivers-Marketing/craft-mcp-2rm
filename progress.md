# Nightshift Progress — 2026-07-21-2003

Append-only notes for the next issue in this run.

## Issue #30 — get_form stale layout after update_form (reopened fix, COMPLETE)

Confirmed against the real installed vendor copy (`/Users/justinl/repos/mbd/vendor/solspace/craft-freeform`,
version **5.15.16**, matches the issue's live-verify session exactly — this
worktree's own `vendor/` has no Freeform package since the plugin only
duck-types it, so verification was done against the mbd site's vendor tree):

- `Solspace\Freeform\Services\Form\LayoutsService` (packages/plugin/src/Services/Form/LayoutsService.php)
  has **no Memo object** — it memoizes directly in four private plain arrays:
  `private array $pages = []`, `$layouts = []`, `$rows = []`, `$formLayouts = []`.
  `pages`/`layouts`/`rows` are keyed by `$form->getId()` (stable DB id, confirmed
  via `getPages()`/`getLayouts()`/`getRows()` bodies — `array_key_exists($form->getId(), ...)`).
  `formLayouts` is keyed by `spl_object_id($form)` inside `getLayout()`.
  Property names match the issue spec exactly: `pages`, `layouts`, `rows`, `formLayouts`.

- Extended `src/support/FreeformFormCacheReset.php`:
  - Added `LAYOUTS_SERVICE_CLASS` const (`Solspace\Freeform\Services\Form\LayoutsService`)
    and `LAYOUTS_SERVICE_ARRAY_PROPERTIES = ['pages', 'layouts', 'rows', 'formLayouts']`.
  - Added `resolveLayoutsService()` — reached via `Freeform::getInstance()->formLayouts`
    (same pattern as `resolveFormsService()`, not a direct container->get()).
  - Added generic `clearArrayProps(?object $service, array $properties)` +
    private `clearArrayProp()` helper — reflection-sets each named property to `[]`.
    Guarded: no-ops on null service / missing property, never throws.
  - `reset()` now calls all three: `clearMemo(FormsService)`, `clearMemo(FieldProvider)`,
    `clearArrayProps(LayoutsService, [...])`. Existing FormsService/FieldProvider
    clearing untouched.
  - Docblock updated with the third taxonomy entry: **container-singleton-with-plain-array-memo
    keyed by stable id** (LayoutsService) — reachable via container singleton,
    but no `Memo::clear()` exists; the array itself must be reflection-emptied.

- **`FreeformFormCacheReset` final shape** (relevant for #31 `delete_form`):
  `reset()` is still the single static no-arg entry point, still called from
  `FreeformScaffoldTools::triggerPersist()`. It now flushes THREE services in
  one call: FormsService memo, FieldProvider memo, LayoutsService's four array
  props. Any future structural-write tool (including #31's `delete_form`) gets
  full coverage for free just by calling `FreeformFormCacheReset::reset()` —
  no new call sites needed, and #31 does NOT need its own LayoutsService flush
  logic; it depends on this fix being present (it is, as of this commit).

- Tests: extended `tests/Unit/Support/FreeformFormCacheResetTest.php` with a
  `clearArrayProps` describe block (anonymous class stub with 4 private array
  props + a `snapshot()` reader) — asserts full-empty, null-service no-op,
  missing-property no-op, and partial-clear (untouched props stay untouched).
  Boot-free, no Craft/Freeform required.

- `composer test`: 498 passed (1170 assertions). `composer analyse`: 0 errors
  (98 files). `vendor/bin/pint` on both touched files: 1 fixer applied
  (`class_attributes_separation` in the test file), reformatted and re-verified green.

- **Live-verify still needed** — this fix was derived from the prior live
  session's diagnosis + confirmed against the exact same vendor version
  (5.15.16) byte-for-byte on property names/keying, but the actual MCP server
  process was NOT restarted/exercised this run (disconnected per run
  instructions). Next live session should SIGHUP-restart `craft-mcp`, run
  `update_form` (add a field, reorder fields) against a real form, then
  immediately `get_form` in the same session and confirm the new/reordered
  fields appear — this is the scenario the previous fix (#28-era, FormsService
  + FieldProvider only) still failed.

## Issue #31 — delete_form MCP tool (COMPLETE, NOT live-verified)

Built `delete_form` in `src/tools/FreeformScaffoldTools.php` (3rd scaffold tool,
alongside create_form/update_form) + a new pure-logic support helper
`src/support/FreeformFormDeletionCascade.php`. All table/column names verified
against the installed vendor source (`/Users/justinl/repos/mbd/vendor/solspace/
craft-freeform`, Freeform 5.15.16) and Craft core migrations.

### A — stale-static crash guard
`deleteForm()` = `SafeExecution::run(fn => FreeformStaleFormCache::guard(fn =>
runDelete(...)))`. guard sits INSIDE SafeExecution so its actionable
ToolCallException ("MCP reload (SIGHUP) required") passes through untouched
(SafeExecution rethrows ToolCallException as-is). Freeform's own deleteById()
does `Submission::find()->formId()->batch()` internally, which trips the
process-lifetime SubmissionQuery static for a same-session form -> guarded.
Note: `collectSubmissionIds()` uses a RAW query on freeform_submissions (NOT
SubmissionQuery), so gathering ids never crashes; only the vendor deleteById
call can, and that's guarded.

### B — full structural orphan cascade
`FormsService::deleteById($id)` drops the form record + per-form content table
+ deletes submission elements, but (verified: all these tables define
formId->freeform_forms.id CASCADE and freeform_submissions.id->elements.id
CASCADE, yet the live DB does NOT cascade them per the issue's empirical
finding) leaves orphans. delete_form cleans the whole set explicitly in FK-safe
order and re-counts to assert 0 remain:
  - form-keyed (col `formId`): freeform_forms_fields, _rows, _pages, _layouts,
    freeform_submissions (meta)
  - element-keyed (submission ids): searchindex (col `elementId`),
    elements_sites (col `elementId`), elements (col `id`)
Confirmed columns: `craft_searchindex.elementId`, `craft_elements_sites.elementId`,
`craft_elements.id` (Craft core Install.php + live `SHOW COLUMNS`);
freeform_* tables keyed by `formId` (Freeform Install.php).
Order rationale: structural children before parents; submission meta (child of
element via id FK) + searchindex/elements_sites before the element rows. Element
specs are OMITTED when the form has 0 submissions (avoids a no-op `IN ()`).
Deletes are idempotent — if the DB's FK cascade IS live, our deletes just find
0 rows and report 0 cleaned; assertion still passes.

Vendor delete is REUSED (not reimplemented) for the form record + content-table
drop + submission-element delete — the "prefer Freeform/Craft APIs" path.
Content table name comes from Freeform's own `Submission::generateContentTableName()`
(duck-typed, class_exists-guarded).

### C — in-process cache flush
After a successful delete, `FreeformFormCacheReset::reset()` runs (unchanged
from #30 — flushes FormsService + FieldProvider memos + LayoutsService arrays).
No new cache logic; #31 just adds the call site on the delete path.

### Tool shape
`deleteForm(?string handle, ?int id, bool dryRun=false, ?string confirm=null,
?RequestContext context=null)`. category CONTENT, dangerous:true. Match by id OR
handle (same as update_form). dryRun previews {form, submissions, contentTable,
wouldDelete: per-table counts}, persists nothing. Confirmation guard: a real
delete requires `confirm` === the target form's exact handle (echoed from the
dryRun preview) or it throws — a bare `delete_form {id:9}` never deletes.
Real-delete return: {form:{id,handle}, submissionsDeleted, contentTableDropped,
cleaned:{table=>count}, orphansRemaining:{table=>count}, orphansClean:bool}.

### Tests / analyse
`composer test`: 511 passed (1233 assertions) — was 498 pre-#31; +13 new
(9 cascade pure-logic in tests/Unit/Support/FreeformFormDeletionCascadeTest.php,
4 structural in FreeformScaffoldToolsTest.php + tool-count 2->3). `composer analyse`:
No errors (99 files). `vendor/bin/pint` on 4 touched files: passed.
Boot-free only — no Craft/Freeform booted (DB methods on the cascade helper are
not unit-exercised; pure buildSpecs/tableLabel/allClean/summaries are).

### LIVE-VERIFY STILL NEEDED (handed off — worktree can't exercise this)
MCP disconnected this run; not verified end-to-end. Next live session:
 1. SIGHUP-restart craft-mcp so the new tool + code load.
 2. create_form a throwaway form; submit it once (front-end or a seeded
    submission) so it has >=1 submission + content-table rows + searchindex rows.
 3. `delete_form {handle:<h>, dryRun:true}` -> confirm the wouldDelete counts
    look right (fields/rows/pages/layouts, freeform_submissions=1,
    searchindex/elements_sites/elements for the submission id).
 4. `delete_form {handle:<h>, confirm:<h>}` -> assert orphansClean:true and every
    orphansRemaining count is 0. Independently spot-check the 8 tables in the DB
    for the formId/element ids = 0 rows, and that the content table was dropped.
 5. Same-session `list_forms` / `get_form <h>` -> form no longer returned (req C).
 6. Also verify the same-session-created-form crash path: create_form then
    immediately delete_form the SAME form in one session -> must return the
    actionable SIGHUP reload message (req A), NOT an uncaught "Undefined array key".
