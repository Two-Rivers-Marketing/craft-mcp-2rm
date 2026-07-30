# QA-driven feature backlog

Running list of features, fixes, and gaps surfaced during the live-QA pass (see [overview/project.md](../overview/project.md)). New items get appended as QA proceeds through the priority list. When an item is committed to, promote it to a `decisions/deferred/` entry and (if it needs design) a `plans/` page. When shipped, move the decision to `decisions/implemented/`.

Legend: **P** = priority (relative), status = `open` / `scoped` / `in-progress` / `done`.

## Freeform

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| `create_form` write tool | med | **done** (pending live verify) | v1 shipped (issue #19): `FreeformScaffoldTools::create_form`, single-page, 6 field types. Creation API + gotchas in [architecture/freeform-integration.md](../architecture/freeform-integration.md); see [decision](../decisions/implemented/2026-07-15-create-form-tool.md). Live-verify (create form + submission after SIGHUP) still open. |
| `get_form` notifications/connections/spamSettings return null | high | **fixed but UNMERGED** (#18) | Fix built + live-verified 2026-07-15 (real `NotificationsService`/`IntegrationsService` reads). Stranded in **draft PR #27** off `worktree-issue-18-freeform-getform`; NOT on `main`. Issue #18 marked closed but code hasn't landed — merge PR #27 to ship. See [architecture/freeform-integration.md](../architecture/freeform-integration.md). |
| `update_form` (edit fields/layout) | low | **done** (pending live verify) | v1 shipped (issue #20): `FreeformScaffoldTools::update_form`, add/remove/reorder fields on an existing single-page form, matched by handle, reusing UID for kept fields so submission data survives. Fields outside the v1 type subset are always preserved untouched. UID-preservation semantics + gotchas in [architecture/freeform-integration.md](../architecture/freeform-integration.md); see [decision](../decisions/implemented/2026-07-15-update-form-tool.md). Live-verify (add/remove/reorder + post-edit submission read after SIGHUP) still open. |
| `list_forms` submissionCount as JSON string elsewhere | low | **done** (#25) | `Serializer.php:101` and `AssetTools.php:75` now `(int)`-cast; grep confirmed 11 other `->count()` sites already cast. |
| `update_form` add-field not rendered in CP | high | **done + merged** (#28) | Root cause was NOT an orphaned row (DB write was correct) — it was a stale **`LayoutPersistence`** memo in the long-running server resolving the new row id to null. Fixed via `FreeformLayoutCacheReset`. Part of the cache-staleness family — see [architecture/freeform-integration.md](../architecture/freeform-integration.md#cache-staleness-family-in-the-long-running-server-26--28--29--30). |
| submission read/count/delete crash for same-session form | high | **done + merged** (#29) | `SubmissionQuery` method-local `static $forms` map, populated once, never invalidated → `Undefined array key` for a form created after the first submission query. Not resettable (method-local static) → detect + return actionable "reload (SIGHUP)" error via `FreeformStaleFormCache::guard`. |
| `get_form` stale field layout after `update_form` (same session) | high | **done + merged** (#30) | Two-part fix: `FieldProvider` memo (first attempt) then the real culprit **`LayoutsService`** plain-array memos keyed by stable form id (what `Form::getLayout()` actually reads). Both folded into shared `FreeformFormCacheReset`. Live-verify still pending. |
| `delete_form` write tool | med | **done + merged** (#31) | Completes Freeform write CRUD. Guarded delete + full 8-table orphan-cascade cleanup + cache flush; dryRun + confirm-by-handle gate + `dangerous:true`. `src/support/FreeformFormDeletionCascade.php`. Live-verify pending. See [decision](../decisions/implemented/2026-07-23-delete-form-tool.md). |

## Neo

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Neo write suite crashed on save (all 4 tools) | high | **done** | `persistBlocks` passed Block objects; Neo wants serialized delta format. Fixed via `toNeoValue()`. Verified live (create/update/reorder/delete + nested-set integrity). See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| `create_neo_block` returns `blockIds: [null,...]` / `create_block_type` returns `blockType.id: null` | low | **done** (#23, pending live re-verify) | Fixed by post-save re-read: `create_neo_block` diffs the re-read block list against pre-save ids (`NeoBlockTree::newBlockIds()`); `create_block_type` resolves the new type by handle after the cache flush. Needs SIGHUP + live echo check. See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| Item 3: `create_block_type` scaffolding | high | **done** | Fixed handle casing (`toCamelCase`→`toHandle`) and a stale-memo bug (new type invisible to follow-up calls until restart; clear `Memoize::$blockTypesByFieldId` after save). Persistence itself was correct. See [../architecture/neo-integration.md](../architecture/neo-integration.md). |
| `create_block_type` stub hardcodes `columnItem` children loop | low | done (#24) | Stub now honors `childBlockTypes`: columnItem children keep the columnItem include; other types dispatch via `columnItemPaths` (single type by name, mixed via `item.type.handle`). |
| Item 4: `upload_asset` (GCS volume) | — | **done** | Verification-only, no defects. See [../architecture/asset-integration.md](../architecture/asset-integration.md). |
| Item 5: childBlocks / positioning edge cases | — | **done** (#22) | Live-QA'd on a scratch entry: integer index, `before:`/`after:` (valid + invalid sibling), `parentBlockId` nesting, legal/illegal childBlocks — all correct, nested-set integrity held. Found + fixed one defect: Neo exposes a **leaf** type's `childBlocks` as `null`, which `NeoBlockTree` treated as allow-any → illegal nesting saved. Now `null` = no children. See [../architecture/neo-integration.md](../architecture/neo-integration.md). Live re-verify of the leaf-rejection pending SIGHUP. |
| `template.exists` wrong for child types + no multi-root awareness | high | open | `get_block_type` reports `exists: false` for types that render fine: wrong path for child types (`_includes/` not `body_blocks/`), no awareness of plugin template roots (e.g. `site-toolkit`). `describe_content_builder` reports `exists: false` for every block type on a stock install. See [../architecture/neo-integration.md](../architecture/neo-integration.md#template-resolution-gotcha). Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| `create_block_type` missing `topLevel`/`parentBlockTypes` params | med | open | No way to declare child-only types or assign parents in one call — requires `tinker` post-creation. `scaffoldTemplate` also hardcodes `body_blocks/` path. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| `newFields` cannot create relation fields | med | open | Supports `plainText`/`richText`/`dropdown`/`lightswitch`/`asset` — missing `entries`/`categories`/`users` with `sources`/`maxRelations`. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |

## Assets

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| `upload_asset` on GCS volume | — | **done** | Root upload, new nested subfolder creation (`qa/2026`), and filename-collision de-dup all verified live with no adapter-specific defects. See [../architecture/asset-integration.md](../architecture/asset-integration.md). |

## Entry / content tools

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Response verbosity — full SEOmatic MetaBundle in every entry response | high | open | Every `create_entry`/`update_entry` returns hundreds of mostly-empty SEOmatic lines. For a 439-entry import this was the dominant cost. Suggested: compact summary by default, `verbose: true` for full dump. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| No Matrix / nested-entry field writes | high | open | Matrix fields in `fields` accept only scalars — writing Matrix content requires hand-rolled `tinker`. Largest time sink of the KCMA session. Neo's `children` shape already models the right pattern. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| No `delete_entry` tool | med | open | `delete_form`/`delete_node`/`delete_neo_block`/`delete_submission` exist but no entry delete. A tool could enforce guardrails (refuse children, warn non-empty channels, soft-delete default). Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| Date fields shift silently on write | high | open | `update_entry` with `"2026-09-14 09:00:00"` reads back 5 hours shifted — UTC↔local confusion, reported as success. Suggested: ISO-8601 with explicit offset, or document the assumed zone. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |

## Cross-cutting / infra

| Item | P | Status | Notes |
| --- | --- | --- | --- |
| Orphan `stimmt` MCP server processes | low | open | Two zombie `vendor/stimmt/craft-mcp/bin/mcp-server` procs (PIDs 2220/17996 as of 2026-07-14) from the pre-rename path; safe to kill. Leftover from other MCP clients. |
| Reload ergonomics | low | open | Code changes need a manual SIGHUP restart of the long-running server; `reload_mcp` only detects new plugins. A smoother dev-reload would speed QA. |
| Long-running server holds process-static caches | med | **done** (#26) | Audited every schema-/content-mutating tool. One new hazard found + fixed: `create_form` left Freeform `FormsService`'s private memo cache stale (get_form-by-handle / list_forms served stale until SIGHUP) → `FreeformScaffoldTools::flushFormCache()`. Everything else safe or already-busted (`flushBlockTypeCaches`, #23's post-save re-read). Audit table in [../architecture/neo-integration.md](../architecture/neo-integration.md). Live re-verify of the create_form freshness pending SIGHUP. |
| mbd snapback jobs failing (not our plugin) | — | note | `modules\snapback\jobs\SnapbackJob` fails on `fopen(...system.png): No such file` — mbd's own snapback module can't write screenshots. Pre-existing; unrelated to craft-mcp. Flagged for mbd, not this repo. |
| Inconsistent identifier params across tools | low | open | `create_neo_block` takes `entryId`; `update_entry`/`get_entry` take `id`. `create_entry` requires `type`. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| `tinker` security error doesn't name the blocked pattern | low | open | Error names the rule family but not the matched construct (e.g. `copy()`). Naming it + pointing at the right tool (`upload_asset`) would save a guess. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| Composer `no-api` needed for public repo consumers | low | open | Without `"no-api": true` on the VCS repo entry, `composer update` fails with GitHub auth error on a public repo. Worth documenting in install docs. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |
| Tag releases closer to merges | low | open | HEAD sat 59 commits ahead of `v1.3.0`; consumers on `^1.3` silently lacked HTTP transport + CRUD tools. Tagged `v1.4.0` at `304662a` to fix. Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md). |

## Cross-references

- [overview/project.md](../overview/project.md) — QA priority order
- [create-form-tool.md](create-form-tool.md)
- [architecture/freeform-integration.md](../architecture/freeform-integration.md)
- [architecture/neo-integration.md](../architecture/neo-integration.md) — template resolution gotcha
- Source: [KCMA field report](../raw/2026-07-28-kcma-build-tool-findings.md) — 2026-07-28, v1.4.0 on kcma.ddev.site
