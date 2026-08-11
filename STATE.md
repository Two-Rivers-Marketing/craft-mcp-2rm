# STATE — craft-mcp-2rm

**Updated:** 2026-08-10
**Status:** Active — v1.6.1 released, all 87 tools live-verified on kcma.ddev.site

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools).
**87 tools, 601 tests, phpstan clean.** Working tree clean, `main` == `origin/main` at `ddffeee`.
KCMA runs v1.6.1. mbd is still on an older tag and needs a SIGHUP-restart pass.

### v1.6.0 / v1.6.1 (2026-08-10) — 12 new tools, live-verified

`create_draft`, `publish_draft`, `list_drafts` (drafts of existing entries only); `get_global_set`;
`get_user`, `create_user`, `update_user` (no `delete_user` — cascades into content ownership);
`render_template`; `list_queue_jobs`, `retry_failed_jobs`; `get_seo`, `update_seo` (conditional on
SEOmatic). All 12 exercised against kcma.ddev.site including write cycles.

v1.6.1 fixed the two defects live testing exposed: an unreachable draft-of-draft guard (`Entry::find()`
excludes drafts, so the guard was dead code and the error misreported "not found"), and `list_drafts`
dumping 6.6KB per draft of which 81% was one SEOmatic field (fields now opt-in, 92% smaller).

Detail: [docs/wiki/log/2026-08-10-new-tools-and-live-verification.md](docs/wiki/log/2026-08-10-new-tools-and-live-verification.md).

### v1.5.1 (2026-07-31) — KCMA field report fixes

7 fixes from a real KCMA build session: template resolution across plugin template roots
(15/22 block types resolve, was 4/22), compact create/update entry responses, ISO-8601 dates,
`topLevel` on `create_block_type`, `entries`/`users` relation field types, `delete_entry`,
`tinker` error naming.

## Next steps

- [ ] **DECIDE: `list_entries` verbosity.** Measured live at **127KB for `limit=25`, 79% SEOmatic**.
      Options and recommendation in
      [log/2026-08-10](docs/wiki/log/2026-08-10-new-tools-and-live-verification.md#open-decision--list_entries-verbosity).
      Left alone because changing the default is breaking for mbd — needs an operator call.
- [ ] **Matrix/nested-entry writes** — biggest remaining functional gap. Matrix fields accept only
      scalars; nested content still requires `tinker`.
- [ ] **SIGHUP mbd's MCP server** + bump it to v1.6.1. Nothing since v1.5.0 is verified there.
- [ ] **Merge PR #27** — #18 `get_form` fix stranded in a draft PR, still not on `main`.
- [ ] `parentBlockTypes` param — deferred from the `topLevel` fix.
- [ ] `get_entry` shares `list_entries`' unfiltered serializer (~6KB, same root cause, lower priority).
- [ ] Inconsistent identifier params (`entryId` vs `id`) — low priority, breaking.
- [ ] Composer `no-api` line in install docs.

## Open questions

- Raise the MCP SDK's `paginationLimit` above the tool count (now 87)? Pagination is correct and
  compliant clients follow `nextCursor`, so this is consumer ergonomics, not a fix. See
  [docs/wiki/architecture/mcp-transport.md](docs/wiki/architecture/mcp-transport.md).

## Resolved

- ~~Tag + live-verify the 12 new tools~~ — done 2026-08-10, v1.6.0/v1.6.1.
- ~~Entry write tools missing from HTTP transport~~ — **no such defect** (2026-08-03). `tools/list`
  is paginated at 50; all tools are advertised across 2 pages. The original verification issued a
  single un-paginated request. See
  [docs/wiki/log/2026-08-03-http-transport-non-bug.md](docs/wiki/log/2026-08-03-http-transport-non-bug.md).
