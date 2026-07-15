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

## Issue #21 (upload_asset live-QA vs GCS) — VERIFICATION-ONLY PASS, no defects, NO commit
- Live-tested against mbd's `media` volume (GCS, `craft\googlecloud\Fs`). Root upload, new nested subfolder creation (`qa/2026`, neither level existed), and filename-collision de-dup (`avoidFilenameConflicts`) all worked correctly — no code change needed. `resolveTargetFolder()`'s `ensureFolderByFullPathAndVolume(..., false)` handles GCS's prefix-only nature fine (Craft's own `assetfolders` table does the bookkeeping; no physical placeholder objects required).
- GOTCHA for future QA: public GCS URLs (`storage.googleapis.com/...`) can return stale `200` briefly after a hard delete (edge cache) — don't verify "object is really gone" via the public URL; check `$fs->fileExists()` on the volume's FS adapter instead. All 3 test assets + 2 scaffold folders confirmed clean via `fileExists()`/`list_asset_folders` after cleanup.
- Test image note: no PIL/Pillow on this host — built a minimal valid PNG by hand with Python's `struct`+`zlib` (IHDR/IDAT/IEND chunks). A 1x1 pixel from a copy-pasted base64 snippet failed Craft's image validation (`Raster.php:186`); a proper 4x4 RGB PNG worked.
- Full writeup: `docs/wiki/architecture/asset-integration.md`. Next QA item per project.md: item 5, Neo childBlocks/positioning edge cases.

## Issue #22 (Neo childBlocks + positioning edge cases) — DEFECT-FIXED, needs LIVE RE-VERIFICATION
- Live-QA'd on disabled scratch Page entry 3632 (now HARD-DELETED, 0 orphan block rows). Positioning is SOLID:
  - integer index insert (`position:1`) → exact placement, existing order preserved, lft/rgt contiguous.
  - `before:<id>` / `after:<id>` valid sibling → correct placement.
  - invalid ref (`before:99999`) and NON-SIBLING ref (nested block id used at top-level scope) → both rejected with a clear error listing the valid sibling IDs for that scope. Correct.
  - `parentBlockId` nesting (1/2/3 levels: multiColumn→columnItem→plainText) → child lands in right scope, sibling order preserved, levels + nested-set (lft/rgt) integrity held after every op.
- **DEFECT (fixed here): childBlocks validation let illegal nesting under a LEAF type through.** Neo represents a leaf block type's `childBlocks` as **null** (not `false`/`[]`), and `NeoBlockTree::parentAllowsChildren(null)`/`childBlocksAllows(null,…)` were LENIENT (returned allow). Reproduced live: nested a `plainText` under a leaf `plainText` (level 4) and it SAVED — Neo recomputes lft/rgt so the nested-set stays numerically valid, but the structure is schema-illegal. Fix: treat `null` the same as `false`/`[]` (hard "no children") in both functions. Array-based rules (`multiColumn=[columnItem]`, `columnItem=[…]`) were already correct — headerBuilder-in-columnItem and plainText-in-multiColumn were properly rejected before the fix.
- Fix is pure-logic in `src/support/NeoBlockTree.php` (2 funcs + docblocks); tests updated in `tests/Unit/Support/NeoBlockTreeTest.php` (null now asserts false). 449 pass, phpstan clean (`--memory-limit=1G`), pint clean.
- **LIVE RE-VERIFY after SIGHUP restart:** on a fresh scratch entry, confirm `create_neo_block` with `parentBlockId=<a leaf block>` (e.g. a plainText/qaScratchBlock) is now REJECTED ("does not allow child blocks"), and that legal nesting (columnItem in multiColumn, plainText in columnItem) still works. The running server has the OLD (lenient) code until restarted.

## Issue #23 (echo real IDs from create_neo_block / create_block_type) — DONE, needs LIVE RE-VERIFY after SIGHUP
- Commit e004c47. Both fixes are echo-only re-reads after the (already correct) save; failures degrade to `[]` / `null` instead of failing the persisted write.
- `create_neo_block`: after `persistTree()`, re-fetches the owner via a FRESH `getElementById` (the just-saved element memoizes its old field value — do NOT read the field off the saved instance), summarizes the re-read blocks, and diffs against the pre-save summaries with new pure helper `NeoBlockTree::newBlockIds(before, after)` → new ids in document (lft) order, robust to any insertion position/parentBlockId.
- `create_block_type`: `resolveSavedBlockTypeId()` resolves the new type by handle via Neo's blockTypes service `getByFieldId()` (NOT `$field->getBlockTypes()` — the field instance may memoize its pre-save list). Works because `flushBlockTypeCaches()` already cleared `Memoize::$blockTypesByFieldId`, so the read hits the DB.
- Tests 449→454 (5 pure-logic newBlockIds tests), phpstan clean (`--memory-limit=1G`), pint clean on all 4 touched PHP files.
- **LIVE RE-VERIFY after SIGHUP restart:** (1) `create_neo_block` with a nested tree on a scratch entry → `blockIds` must be real ints matching the DB rows, in preorder; try a mid-list `position` and a `parentBlockId` insert too. (2) `create_block_type` with a throwaway type → `blockType.id` must be a real int; then delete the scratch type + entry.
- Wiki: neo-integration.md "Known limitations" null-id bullet moved to a resolved "Real ID echo" section; qa-feature-backlog row marked done (pending live re-verify).
