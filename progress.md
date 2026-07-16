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
