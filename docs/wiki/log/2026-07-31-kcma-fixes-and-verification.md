---
type: log
timestamp: 2026-07-31
---

# 2026-07-31 — KCMA field report fixes, v1.5.1 release, live verification

**Operation:** log (session wrap). **Scope:** ingested the KCMA field report, implemented 7 of 11 findings, tagged v1.5.0 then v1.5.1, live-verified on kcma.ddev.site.

## What happened

1. **Ingested `raw/2026-07-28-kcma-build-tool-findings.md`** — 8 ranked findings + packaging observations from a real build session (439-entry import, 22 Neo blocks, 6 asset uploads on kcma.ddev.site, v1.4.0 over HTTP transport). Curated into 11 backlog items across Neo, Entry/content (new section), and Cross-cutting.

2. **Implemented 7 fixes** (commit `3c30320` + `06b4a91`):
   - **template.exists** — resolved via `View::resolveTemplate()` across all registered roots. Tries nesting-appropriate paths for child types (`_includes/columnItems/` not `body_blocks/`). Plugin roots discovered by firing `EVENT_REGISTER_SITE_TEMPLATE_ROOTS` to get root keys, then trying prefixed candidates. Reports `servedBy` (e.g. `site-toolkit`, `project`).
   - **Response verbosity** — `create_entry`/`update_entry` return only written fields; `get_entry`/`list_entries` unchanged.
   - **Date format** — `Serializer` + `EntryTools` use ISO-8601 with tz offset (`format('c')`). Breaking change for consumers parsing `Y-m-d H:i:s`.
   - **`topLevel` param** on `create_block_type` (default `true`).
   - **`entries`/`users` in `newFields`** with `sources`/`maxRelations` options.
   - **`delete_entry`** — new tool, `dryRun` support, `dangerous: true`.
   - **`tinker` error naming** — names the matched blocked construct, hints `upload_asset` for `copy()`.

3. **Tagged v1.5.0, then v1.5.1** after fixing the plugin-root resolution. KCMA updated via `ddev composer update`.

4. **Live verification on kcma.ddev.site** (v1.5.1, HTTP transport):
   - template.exists: 15/22 block types resolve (was 4/22). `servedBy` reports `project` or `site-toolkit`. The 7 remaining `exists: false` are genuinely unbuilt types.
   - Date format: `2026-07-28T12:14:00-05:00` confirmed.
   - `create_block_type`: `topLevel` param visible in schema.
   - `newFields`: `entries`/`users` in tool description.
   - Entry write tools (`create_entry`, `update_entry`, `delete_entry`, `tinker`) not exposed in HTTP transport tool list — pre-existing issue, not caused by these changes.

## Durable knowledge

- **Craft `resolveTemplate` requires root-key prefix for plugin roots.** `resolveTemplate('body_blocks/foo.twig')` only searches the site templates path. To find templates in a registered root like `site-toolkit`, you must try `resolveTemplate('site-toolkit/body_blocks/foo.twig')`. Discovered by live verification — the first fix (bare-path resolution) passed tests but failed live because tests are boot-free. Documented in [../architecture/neo-integration.md](../architecture/neo-integration.md#template-resolution-gotcha).

- **Date `Y-m-d H:i:s` format loses timezone** — Craft stores dates with timezone, but the old format silently dropped the offset. A 5-hour shift in `America/New_York` reported as success. ISO-8601 `format('c')` is the fix.

## Still pending

- **4 backlog items open:** Matrix/nested-entry writes (biggest gap), inconsistent identifier params, Composer `no-api` docs, release tagging cadence.
- **Entry write tools not in HTTP transport tool list** — `create_entry`, `update_entry`, `delete_entry`, `tinker` absent. Other dangerous tools (`create_block_type`, `delete_form`, etc.) show fine. Pre-existing; needs investigation.
- **`parentBlockTypes` param** deferred — requires modifying+saving other block types.
- **All fixes pending SIGHUP on mbd** for that install's verification.

## Cross-references

- [../architecture/neo-integration.md](../architecture/neo-integration.md) — template resolution gotcha
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md) — 7 items marked done
- [../raw/2026-07-28-kcma-build-tool-findings.md](../raw/2026-07-28-kcma-build-tool-findings.md) — source field report
- [2026-07-30-kcma-field-report-ingest.md](2026-07-30-kcma-field-report-ingest.md) — the ingest log from this session
