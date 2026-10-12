# STATE — craft-mcp-2rm

**Updated:** 2026-10-11
**Status:** Active — `main` is `5aff795` (mcp/sdk ^0.7.1, PR #35 merged, **untagged**); newest tag v1.8.0

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools).
**87 tools, 608 tests (reported by PR #35's run; was 606), phpstan clean.** KCMA runs v1.7.0. mbd is still on v1.5.0 and needs a
bump + SIGHUP — note v1.7.0 is **breaking** for it (see below).

### mcp/sdk ^0.7.1 (2026-10-11, PR #35, untagged)

Moves off `mcp/sdk` 0.6.0, which is under PKSA-p9gd-j6gr-6f9t / CVE-2026-53965 (`>=0.5.0,<0.7.1`) and was blocking
consumer installs under Composer's default security settings. Loading pinned eager; new 4 MiB stdio/HTTP caps.
Live-verified in a throwaway Craft 5 + Neo site only — **not** on a real consuming site, the
Freeform/Commerce/Navigation/SEOmatic tools, SIGHUP, or `reload_mcp`. Consumers get it only via a tag or by
requiring the branch/commit. Detail: [docs/wiki/architecture/mcp-sdk-0-7-upgrade.md](docs/wiki/architecture/mcp-sdk-0-7-upgrade.md).

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

- [ ] **Tag a release** carrying the sdk fix, then verify on a real consuming site (KCMA/mbd) incl. SIGHUP + `reload_mcp`.
- [ ] **`CHANGELOG.md:15` is wrong** — says a stdio message over 4 MiB is "rejected"; it is discarded with a log warning and no reply (only HTTP gives `413`).
- [ ] **PHP 8.3 still named** though `composer.json` requires `^8.4`: `docs/installation.md:10`, `docs/installation.md:254`, `CLAUDE.md:5`, `.github/workflows/release.yml:25`.
- [ ] **`README.md:20` calls the repo private**; it is public.
- [ ] Decide whether to keep `config.policy.advisories.block: false` in `composer.json` (hides the block from this repo's own CI); bump `enshrined/svg-sanitize` in the lock (3 medium advisories).
- [ ] Stdio messages ≥ ~200 KiB are fragmented into parse errors (pre-existing, old sdk too) — uninvestigated.
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

- Lazy vs eager element loading for the HTTP transport (eager pinned today) — owner's call; see the sdk 0.7 page.
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

## Superseded

- *(superseded 2026-10-11)* "Updated 2026-08-11 · Status: Active — v1.7.0 released; #18 landed" and "606 tests" — newest tag is v1.8.0 and `main` is `5aff795` with 608 tests reported; see [docs/wiki/log/2026-10-11-mcp-sdk-advisory-fix.md](docs/wiki/log/2026-10-11-mcp-sdk-advisory-fix.md). v1.8.0 itself has no STATE entry yet.
- *(superseded 2026-10-11)* "Bump mbd to v1.7.0" — the target is now at least v1.8.0, plus a future tag carrying the sdk fix.
