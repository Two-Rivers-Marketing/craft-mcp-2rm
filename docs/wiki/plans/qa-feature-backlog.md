# QA-driven feature backlog

Running list of features, fixes, and gaps surfaced during the live-QA pass (see [overview/project.md](../overview/project.md)). New items get appended as QA proceeds through the priority list. When an item is committed to, promote it to a `decisions/deferred/` entry and (if it needs design) a `plans/` page. When shipped, move the decision to `decisions/implemented/`.

Legend: **P** = priority (relative), status = `open` / `scoped` / `in-progress` / `done`.

## Freeform

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| `create_form` write tool | med | **done** (pending live verify) | v1 shipped (issue #19): `FreeformScaffoldTools::create_form`, single-page, 6 field types. Creation API + gotchas in [architecture/freeform-integration.md](../architecture/freeform-integration.md); see [decision](../decisions/implemented/2026-07-15-create-form-tool.md). Live-verify (create form + submission after SIGHUP) still open. |
| `get_form` notifications/connections/spamSettings return null | high | open | Keyword-dump approach misses Freeform 5's real structure; defeats "why didn't this submission create an entry". Needs real service reads. See [architecture/freeform-integration.md](../architecture/freeform-integration.md). |
| `update_form` (edit fields/layout) | low | **done** (pending live verify) | v1 shipped (issue #20): `FreeformScaffoldTools::update_form`, add/remove/reorder fields on an existing single-page form, matched by handle, reusing UID for kept fields so submission data survives. Fields outside the v1 type subset are always preserved untouched. UID-preservation semantics + gotchas in [architecture/freeform-integration.md](../architecture/freeform-integration.md); see [decision](../decisions/implemented/2026-07-15-update-form-tool.md). Live-verify (add/remove/reorder + post-edit submission read after SIGHUP) still open. |
| `list_forms` submissionCount as JSON string elsewhere | low | **done** (#25) | `Serializer.php:101` and `AssetTools.php:75` now `(int)`-cast; grep confirmed 11 other `->count()` sites already cast. |
| **`update_form`/`create_form` add-field leaves field orphaned → not rendered in CP** | high | open (#28) | Live CP use: adding a `dropdown` via `update_form` created the field record + a layout row but left `craft_freeform_forms_fields.rowId = null` (orphaned), AND the new row was created with a blank `uid` (dropped by `LayoutsService::attachRows()` even after rowId is fixed). Tool reported success; CP rendered nothing. Scope not yet pinned — create-path vs update-path, dropdown-only vs any-added-field — see the disambiguator in `docs/live-verify-handoff-2026-07-15.md`. |

## Neo

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Neo write suite crashed on save (all 4 tools) | high | **done** | `persistBlocks` passed Block objects; Neo wants serialized delta format. Fixed via `toNeoValue()`. Verified live (create/update/reorder/delete + nested-set integrity). See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| `create_neo_block` returns `blockIds: [null,...]` / `create_block_type` returns `blockType.id: null` | low | **done** (#23, pending live re-verify) | Fixed by post-save re-read: `create_neo_block` diffs the re-read block list against pre-save ids (`NeoBlockTree::newBlockIds()`); `create_block_type` resolves the new type by handle after the cache flush. Needs SIGHUP + live echo check. See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| Item 3: `create_block_type` scaffolding | high | **done** | Fixed handle casing (`toCamelCase`→`toHandle`) and a stale-memo bug (new type invisible to follow-up calls until restart; clear `Memoize::$blockTypesByFieldId` after save). Persistence itself was correct. See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| `create_block_type` stub hardcodes `columnItem` children loop | low | done (#24) | Stub now honors `childBlockTypes`: columnItem children keep the columnItem include; other types dispatch via `columnItemPaths` (single type by name, mixed via `item.type.handle`). |
| Item 4: `upload_asset` (GCS volume) | — | **done** | Verification-only, no defects. See [../architecture/asset-integration.md](../architecture/asset-integration.md). |
| Item 5: childBlocks / positioning edge cases | — | **done** (#22) | Live-QA'd on a scratch entry: integer index, `before:`/`after:` (valid + invalid sibling), `parentBlockId` nesting, legal/illegal childBlocks — all correct, nested-set integrity held. Found + fixed one defect: Neo exposes a **leaf** type's `childBlocks` as `null`, which `NeoBlockTree` treated as allow-any → illegal nesting saved. Now `null` = no children. See [../architecture/neo-integration.md](../architecture/neo-integration.md). Live re-verify of the leaf-rejection pending SIGHUP. |

## Assets

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| `upload_asset` on GCS volume | — | **done** | Root upload, new nested subfolder creation (`qa/2026`), and filename-collision de-dup all verified live with no adapter-specific defects. See [../architecture/asset-integration.md](../architecture/asset-integration.md). |

## Cross-cutting / infra

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Orphan `stimmt` MCP server processes | low | open | Two zombie `vendor/stimmt/craft-mcp/bin/mcp-server` procs (PIDs 2220/17996 as of 2026-07-14) from the pre-rename path; safe to kill. Leftover from other MCP clients. |
| Reload ergonomics | low | open | Code changes need a manual SIGHUP restart of the long-running server; `reload_mcp` only detects new plugins. A smoother dev-reload would speed QA. |
| Long-running server holds process-static caches | med | **done** (#26) | Audited every schema-/content-mutating tool. One new hazard found + fixed: `create_form` left Freeform `FormsService`'s private memo cache stale (get_form-by-handle / list_forms served stale until SIGHUP) → `FreeformScaffoldTools::flushFormCache()`. Everything else safe or already-busted (`flushBlockTypeCaches`, #23's post-save re-read). Audit table in [../architecture/neo-integration.md](../architecture/neo-integration.md). Live re-verify of the create_form freshness pending SIGHUP. |
| mbd snapback jobs failing (not our plugin) | — | note | `modules\snapback\jobs\SnapbackJob` fails on `fopen(...system.png): No such file` — mbd's own snapback module can't write screenshots. Pre-existing; unrelated to craft-mcp. Flagged for mbd, not this repo. |

## Cross-references

- [overview/project.md](../overview/project.md) — QA priority order
- [create-form-tool.md](create-form-tool.md)
- [architecture/freeform-integration.md](../architecture/freeform-integration.md)
