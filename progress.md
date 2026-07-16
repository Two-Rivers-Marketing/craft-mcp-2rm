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
