# Nightshift Progress — 2026-07-15-1125

Append-only notes for the next issue in this run.
Write what the next person needs to know, not what you did.

## Issue #15 (pint formatting)
- src/tools is now pint-clean (`vendor/bin/pint --test src/tools` passes). Only fix needed was import ordering in DebugTools/GraphqlTools/TinkerTools/DatabaseTools.
- `composer lint:test` repo-wide should now pass too — the 4 failing files were the only offenders. CLAUDE.md gotcha about them is stale; consider updating.

## Issue #19 (create_form v1) — DONE, needs LIVE VERIFICATION
- New tool `create_form` in `src/tools/FreeformScaffoldTools.php` (+ pure logic `src/support/FreeformFormPlan.php`). Registered in ToolRegistry behind `FreeformScaffoldTools::isAvailable()`.
- **The Freeform 5.15 form-creation API I discovered** (fully documented in `docs/wiki/architecture/freeform-integration.md` → "Verified 5.15 form-creation API"):
  - No `FormsService::create`/`save`. Forms persist by triggering CP events: `Event::trigger(FormsController::class, EVENT_CREATE_FORM, $persistEvent)` then `EVENT_UPSERT_FORM`, where `$persistEvent = new PersistFormEvent($payload)` and `$payload = (object)['form'=>…, 'layout'=>…]`. Modeled on `FormGenerationService::generate()` (the AI generator, minus AI).
  - Layout is a flat UID-joined tree: `layout.{pages,layouts,rows,fields}`; field→`rowUid`, row/page→`layoutUid`.
  - Fields are inline (no field library). Each field = `typeClass` (`Solspace\Freeform\Fields\Implementations\*Field`) + `properties` object. `LayoutPersistence` validates against `PropertyProvider::getEditableProperties($typeClass)` (container-resolvable), so payload must carry EVERY editable prop; `label`+`handle` have Required validators. Tool seeds defaults then overrides label/handle/required (+ `optionConfiguration` for dropdown).
  - **GOTCHA (biggest live risk):** `FormPersistence::handleFormCreate` reads `Craft::$app->getUser()->getIdentity()->id` with no null guard. The MCP server has NO logged-in user → bare trigger fatals. Tool sets an admin identity (`User::find()->admin(true)->status(null)->one()`) for the save, restores in `finally`. **`setIdentity` on the MCP server's user component is the main thing to confirm live** — I could not test it (the session's tinker classifier blocks impersonation calls; verification handed off).
- All Freeform refs are string FQCNs / duck-typed (no `use Solspace\Freeform\*`), so class loads without the plugin. One phpstan ignore added for the `Event::trigger` duck-typed event arg.
- Tests: 28 new (structural + pure-logic, boot-free). Suite 449 pass, phpstan clean (`--memory-limit=1G`), pint clean. Note: pure-logic tests can't exercise `StringHelper::toHandle()` (needs Craft booted — fatals on null language boot-free), so handle-defaulting is covered live only; all fixtures pass explicit handles.
- docs/tools/ is STALE for all 2RM additions (no Neo/Freeform tools documented there at all); I only added `create_form` to the Dangerous Tools table. A full docs/tools refresh for Neo+Freeform is unfiled cleanup.
