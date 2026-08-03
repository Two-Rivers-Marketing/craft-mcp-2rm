# STATE — craft-mcp-2rm

**Updated:** 2026-07-31
**Status:** Active — v1.5.1 released, live-verified on kcma.ddev.site

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). 532 tests, phpstan clean.

### v1.5.1 (2026-07-31) — KCMA field report fixes

7 fixes from a real KCMA build session (439-entry import, 22 Neo blocks, 6 asset uploads). Live-verified on kcma.ddev.site over HTTP transport:

- **template.exists** — resolves across all registered template roots (project + plugin), nesting-aware paths, reports `servedBy`. 15/22 block types resolve on KCMA (was 4/22).
- **Response verbosity** — `create_entry`/`update_entry` return only written fields.
- **Date format** — ISO-8601 with tz offset everywhere. Breaking: old `Y-m-d H:i:s` consumers.
- **`topLevel` param** on `create_block_type` (default `true`).
- **`entries`/`users` in `newFields`** with `sources`/`maxRelations`.
- **`delete_entry`** — new tool with `dryRun`, `dangerous: true`.
- **`tinker` error naming** — names the matched blocked construct.

### Unreleased (committed to `main`, not tagged) — 12 new tools

`create_draft`, `publish_draft`, `list_drafts` (drafts of existing entries only); `get_global_set`;
`get_user`, `create_user`, `update_user` (no `delete_user` — cascades into content ownership);
`render_template`; `list_queue_jobs`, `retry_failed_jobs`; `get_seo`, `update_seo` (conditional on
SEOmatic). 600 tests, phpstan clean. **Boot-free tests only — none of it is live-verified.**

## Next steps

- [ ] **Tag + live-verify the 12 new tools** on kcma.ddev.site. Nothing beyond structural reflection
      has exercised group assignment, activation, queue retry, template render, or SEO writes.
- [ ] **Matrix/nested-entry writes** — biggest remaining gap. Matrix fields accept only scalars; nested content requires `tinker`.
- [ ] **`parentBlockTypes` param** — deferred from `topLevel` fix.
- [ ] **Merge PR #27** — #18 `get_form` fix stranded in draft PR.
- [ ] **SIGHUP mbd's MCP server** — picks up v1.5.1 for that install.
- [ ] Inconsistent identifier params (`entryId` vs `id`) — low priority, breaking.
- [ ] Composer `no-api` line in install docs.

## Open questions

- Raise the MCP SDK's `paginationLimit` above the tool count (now 75)? Pagination is correct and
  compliant clients follow `nextCursor`, so this is consumer ergonomics, not a fix. See
  [docs/wiki/architecture/mcp-transport.md](docs/wiki/architecture/mcp-transport.md).

## Resolved

- ~~Entry write tools missing from HTTP transport~~ — **no such defect** (2026-08-03). `tools/list`
  is paginated at 50; all 75 tools are advertised across 2 pages. The original verification issued a
  single un-paginated request. See
  [docs/wiki/log/2026-08-03-http-transport-non-bug.md](docs/wiki/log/2026-08-03-http-transport-non-bug.md).
