# STATE — craft-mcp-2rm

**Updated:** 2026-08-11
**Status:** Active — v1.7.0 released; #18 landed, `list_entries` verbosity closed

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools).
**87 tools, 606 tests, phpstan clean.** KCMA runs v1.7.0. mbd is still on v1.5.0 and needs a
bump + SIGHUP — note v1.7.0 is **breaking** for it (see below).

### v1.7.0 (2026-08-11) — #18 landed, `list_entries` fields opt-in

- **`get_form` #18 fix finally on `main`** (`d402bd8`). PR #27 was **not merged** — 7 of its 8 commits
  were already on `main` (branch-timing artifact inflating the diff to 21 files/+1004), so the one
  real commit `429ae4d` was cherry-picked and the PR closed as superseded. Live-verified on KCMA:
  `notifications`/`connections`/`spamSettings` all lists, none `null`.
- **BREAKING: `list_entries` omits custom fields** unless `includeFields: true`. Live-measured
  **127,605 → 11,448 bytes at `limit=25` (92% smaller)**. `includeFields` is appended, not inserted,
  so positional callers don't shift. `get_entry` unchanged. This closes the field report's finding #2.

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

- [ ] **Matrix/nested-entry writes** — the biggest remaining functional gap. Matrix fields accept
      only scalars; nested content still requires `tinker`.
- [ ] **Bump mbd to v1.7.0 + SIGHUP.** Nothing since v1.5.0 is verified there, and **v1.7.0 changes
      `list_entries`' default shape** — check mbd for callers relying on `fields` before updating.
- [ ] `list_forms` returns `forms` as an **object map keyed by id string**, not an array, unlike every
      other `list_*` tool. Cost real debugging time twice. Groups with the identifier-consistency item.
- [ ] `parentBlockTypes` param — deferred from the `topLevel` fix.
- [ ] Inconsistent identifier params (`entryId` vs `id`) — low priority, breaking.
- [ ] Composer `no-api` line in install docs.
- [ ] Delete merged branch `worktree-issue-18-freeform-getform` (PR #27 closed).

## Open questions

- Raise the MCP SDK's `paginationLimit` above the tool count (now 87)? Pagination is correct and
  compliant clients follow `nextCursor`, so this is consumer ergonomics, not a fix. See
  [docs/wiki/architecture/mcp-transport.md](docs/wiki/architecture/mcp-transport.md).

## Resolved

- ~~`list_entries` verbosity decision~~ — took the breaking option 2026-08-11, v1.7.0. 92% smaller.
- ~~Merge PR #27 / land the #18 `get_form` fix~~ — landed 2026-08-11 by cherry-pick; PR closed
  unmerged (7 of 8 commits were already on `main`).
- ~~Tag + live-verify the 12 new tools~~ — done 2026-08-10, v1.6.0/v1.6.1.
- ~~Entry write tools missing from HTTP transport~~ — **no such defect** (2026-08-03). `tools/list`
  is paginated at 50; all tools are advertised across 2 pages. The original verification issued a
  single un-paginated request. See
  [docs/wiki/log/2026-08-03-http-transport-non-bug.md](docs/wiki/log/2026-08-03-http-transport-non-bug.md).
