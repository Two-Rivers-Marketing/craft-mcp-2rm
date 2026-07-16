# Nightshift Progress — 2026-07-16-1238

Append-only notes for the next issue in this run.

## #28 — update_form orphaned added fields (rowId null) — FIXED (commit 5979147)

Root cause was NOT the payload. `FreeformScaffoldTools::buildUpdatePayload()`
already emits a fresh row per added field, and `create_form`/`update_form`
share `triggerPersist()`. The bug lives in Freeform's own
`LayoutPersistence::getRecordId()` (vendor/solspace/.../Bundles/Persistence/
LayoutPersistence.php): it memoizes a form's `rowUid -> rowId` map in a private
`$cache` on first query. Built for a single web request. In the long-running
MCP server, LayoutPersistence is instantiated ONCE at Freeform boot and binds
`handleLayoutSave` to `FormsController::EVENT_UPSERT_FORM`, so one instance with
stale `$cache`/`$rowContents` serves every tool call. After a form's first save
populates the cache, a later `update_form` add creates the new
`freeform_forms_rows` record but `saveFields` resolves its id via the stale
cache -> null -> orphaned field.

### Fix pattern (reuse for any future Freeform persistence bug)
`src/support/FreeformLayoutCacheReset.php` — clears `$cache`/`$rowContents` on
every bound LayoutPersistence instance BEFORE the persist. Key gotcha:
`Craft::$container->get(LayoutPersistence::class)` returns a FRESH, UNBOUND
instance (verified via tinker) — NOT the event-bound one. The only handle on the
live instance is the Yii event registry (`yii\base\Event::$_events`, private
static, read via reflection). Called from `triggerPersist()` so both create and
update paths are covered by one insertion point. Same family as the pre-existing
`flushFormCache()` (FormsService Memo) — guarded string-FQCN reflection,
best-effort, never fatals, loads without Freeform.

### Gotchas hit
- `ReflectionProperty::setAccessible(true)` is a no-op since PHP 8.1 and
  DEPRECATED in 8.5 (dev env runs 8.5) — omit it (getValue/setValue work on
  private props without it). Existing `flushFormCache` already omits it.
- Live-verify is only via read-only tinker inspection (worktree code isn't the
  live MCP server; needs SIGHUP on symlinked main). Diagnosis was confirmed by
  reading the actual bound instance's `$cache` on the live install — it held
  populated `FormRowRecord` keys + 6 stale `rowContents` UIDs. The real write
  path still needs a live SIGHUP + create-then-update run to confirm end-to-end.

Files: `src/support/FreeformLayoutCacheReset.php` (new),
`src/tools/FreeformScaffoldTools.php` (import + 1 call in triggerPersist),
`tests/Unit/Support/FreeformLayoutCacheResetTest.php` (new, boot-free).
Tests 477 passed, phpstan clean.

## #29 — get/list/count/delete submissions crash for a session-created form — FIXED

Same cache FAMILY as #28 but the #28 reflection reset does NOT transfer, and
this is the key finding: Freeform's `SubmissionQuery::beforePrepare()`
(vendor/solspace/.../Elements/Db/SubmissionQuery.php, v5.15.16) memoizes its
formId->Form map in **METHOD-LOCAL `static` variables** (`static $forms;`,
`static $formHandleToIdMap;`, `static $formIdToHandleMap;` at lines 135-137),
populated once under the `null === $formHandleToIdMap` guard (line 241) and
never rebuilt. Function-local statics are NOT class properties, so
`ReflectionProperty` cannot reach them and NOTHING in userland can clear them —
they genuinely live until the process restarts (SIGHUP). A form created after
the first submission query is absent from the frozen map; when a later query
resolves its id, `$forms[$formId]` (line 311) is an undefined key -> Yii raises
`yii\base\ErrorException` "Undefined array key <formId>" with
`getFile()` = SubmissionQuery.php. Confirmed the ErrorException class/message
shape via read-only tinker.

### Fix approach chosen: DETECT + clear signal (reset is impossible)
Because no reset exists, the fix makes every crash-prone submission path fail
LOUDLY-BUT-CLEARLY instead of with an opaque "Undefined array key":
`src/support/FreeformStaleFormCache.php` (new) — `isStaleFormCacheError()`
matches the crash signature (message contains "Undefined array key" AND
originates in a Freeform SubmissionQuery file/trace, case-insensitive, walks the
getPrevious() chain), and `guard(callable)` wraps a submission-query op,
translating the crash into a `ToolCallException` with an actionable "MCP reload
required (SIGHUP)…" message (original kept as `->getPrevious()`), rethrowing all
other throwables untouched. All Freeform identifiers are plain strings (never
imported) so it loads without the plugin.

Wired into `FreeformTools`: `getSubmission`, `listSubmissions`,
`deleteSubmission` (resolve + deleteElement), `exportSubmissions` wrap their
query executions in `guard()`. `submissionCount()` (list_forms) now returns
`int|string|null` and degrades to `FreeformStaleFormCache::RELOAD_SIGNAL`
('reload_required') on the stale error instead of a silent null — list_forms
still succeeds for every other form.

### Scope notes
- The issue's item #3 (form-delete cascade orphaning a `craft_freeform_submissions`
  row via `Freeform::forms->deleteById`) is NOT reachable: there is no
  delete_form MCP tool (FreeformScaffoldTools only has create_form/update_form).
  Logged in fix_plan.md. delete_submission (element API) does not hit that path.
- Live-verify still needed: worktree isn't the live server. Confirm end-to-end
  with SIGHUP -> create_form -> submit -> get/list/count/delete and check the
  clear reload message appears (and no raw "Undefined array key").

### Cache-family patterns (for #30 get_form stale field layout)
Two distinct sub-families now documented:
1. **Resettable class-property memo** (#28 LayoutPersistence `$cache`/`$rowContents`):
   private CLASS props on an event-bound instance -> reach the live instance via
   the Yii event registry (`yii\base\Event::$_events`) and clear via reflection.
   `FreeformLayoutCacheReset` is the template.
2. **Unresettable method-local static** (#29 SubmissionQuery): function statics —
   reflection CANNOT touch them; only SIGHUP clears them. Best you can do is
   detect the failure signature and emit a clear reload signal.
For #30, FIRST determine which sub-family the stale get_form layout is: if it's
a service-level Memo or a private property on a resolvable/bound instance, the
#28 reflection-reset template applies; if it's a method-local static (like #29),
only a detect-and-signal remedy is possible. Also check whether
`FormsService::flushFormCache()`/existing `flushFormCache()` in
FreeformScaffoldTools already rebuilds the get_form layout after update_form.

Files: `src/support/FreeformStaleFormCache.php` (new),
`src/tools/FreeformTools.php` (import + submissionCount signal + 4 guard sites),
`tests/Unit/Support/FreeformStaleFormCacheTest.php` (new, boot-free, 10 cases).
Tests 487 passed, phpstan clean.

## #30 — get_form serves stale field layout after update_form — FIXED

Confirmed FieldProvider's memo sub-family BEFORE writing code (per instructions):
opened `vendor/solspace/craft-freeform/packages/plugin/src/Bundles/Fields/
FieldProvider.php` on mbd's live install. `FieldProvider::getRows(?int $formId)`
memoizes a form's raw field ROWS (a straight `FormFieldRecord` DB query) in a
private `Memo $cache` under prefix `'rows'`, keyed by the form's real, STABLE
database id — not by Form-object identity (contrast `getFields(Form $form)`'s
own `'by-form'` cache, keyed by `spl_object_id($form)`, which self-invalidates
whenever a fresh Form entity loads). `Form::getLayout()->getFields()` resolves
through `LayoutsService::getFields($form)` -> `FieldProvider::getFields($form)`
-> `getRows($formId)`, so once a form's rows are memoized, EVERY later
get_form for that formId returns the same field list forever, regardless of
how fresh the Form object is — this is `get_form`'s stale-read root cause.

This is sub-family 1 from #29's writeup (**resettable class-property memo**),
NOT #29's unresettable method-local-static family. Confirmed via read-only
`tinker` on the live install: `FieldProvider::class` is registered as a
genuine Yii container **singleton** (`Freeform::initContainerItems()`, "Providers
with caches" block) — unlike #28's `LayoutPersistence` (which is event-bound
only; `$container->get()` returns a fresh, unbound instance there), here
`\Craft::$container->get(FieldProvider::class)` called twice returned the
IDENTICAL object (`singletonSame: true`), with a private `cache` property
holding a `Solspace\Freeform\Library\Cache\Memo` (public `clear()` method) —
same shape as `FormsService::$cache` (#26's `flushFormCache()`). So the #28
reflection-reset template applies directly, and no event-registry lookup is
needed — just `$container->get()` + one reflection-get + `Memo::clear()`.

### Converged on a shared helper (as the issue asked)

Extended/refactored #26's `flushFormCache()` (private method, only in
`FreeformScaffoldTools`, only cleared `FormsService`) into a new support
class: `src/support/FreeformFormCacheReset.php`. `FreeformFormCacheReset::
reset()` now clears BOTH `FormsService::$cache` (forms lookups) AND
`FieldProvider::$cache` (field rows) in one call, via a shared `clearMemo()`
(public, pure reflection logic — no Craft/Freeform needed to exercise it
directly, which is what the boot-free test covers).

Wired into the SINGLE existing convergence point both create_form and
update_form already shared: `FreeformScaffoldTools::triggerPersist()`. Removed
the two explicit `$this->flushFormCache()` call sites (one in `createForm()`,
one in `updateForm()`) and the old private method entirely; replaced with one
`FreeformFormCacheReset::reset()` call inside `triggerPersist()`, right after
`assertNoPersistErrors()` confirms the save succeeded and before returning the
form. Any FUTURE Freeform structural-write tool that routes through
`triggerPersist()` gets this flush for free — no need to remember to call it.

Verified remove AND rename/reorder are covered: the fix isn't payload-shape
specific — `getRows($formId)`/`FormsService::$cache` are wiped unconditionally
on every successful `triggerPersist()` call regardless of what changed in the
diff (add/remove/rename/reorder all funnel through the same `buildUpdatePayload`
-> `persistFormUpdate` -> `triggerPersist` path already used for #28's fix), so
the next `getLayout()`/`get_form` always re-queries fresh rows.

### Gotchas / notes for future cache-family bugs
- Same "check which sub-family first" method as #29 paid off immediately:
  reading the vendor source + one read-only tinker singleton-identity check
  (`$a = $container->get($class); $b = $container->get($class); $a === $b`)
  settled the reflection-vs-detect-only question before any code was written.
- `FreeformFormCacheReset::clearMemo()` is deliberately public and takes a
  plain `?object` so it's testable with bare stubs (mirrors the existing
  `FreeformLayoutCacheReset` test style) without needing Craft/Freeform
  booted; `reset()` itself just resolves the two real services (each behind
  its own `class_exists()` guard) and delegates to it.
- pint removed the leading `\` from `\Craft::$container` (global-namespace
  import fixer) — harmless, `use Craft;` already covers it.

Files: `src/support/FreeformFormCacheReset.php` (new, replaces #26's
`flushFormCache()`), `src/tools/FreeformScaffoldTools.php` (import, 1 call
site in `triggerPersist()`, removed 2 old call sites + old private method),
`tests/Unit/Support/FreeformFormCacheResetTest.php` (new, boot-free, 8 cases).
Tests 494 passed, phpstan clean.

### Live-verify still needed
Worktree isn't the live MCP server (symlinked `mbd/vendor/2rm/craft-mcp`
needs SIGHUP to pick up code). Confirm end-to-end: create_form (6 fields) ->
update_form removing a field -> get_form in the SAME session shows the field
gone (repro from the issue body); then repeat for a rename-only and a
reorder-only update_form call and confirm get_form reflects each without a
restart.
