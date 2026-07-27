---
type: log
timestamp: 2026-07-27
---

# 2026-07-27 — live-verify fallout, nightshift `2026-07-21-2003`, and the cache-staleness taxonomy

**Operation:** log (session wrap). **Scope:** the arc from merging the 2026-07-16 cache fixes, through the mbd agent's live-verify results, into a second nightshift run (#30 completion + `delete_form`) and both merges. Plus the #18 loose-end resolution.

## What happened

1. **Merged nightshift `2026-07-16-1238`** (#28/#29/#30-first-attempt) to `main` (`1ca05ad`) — three fixes in the "stale in-process Freeform cache" family, pushed. The wrap commit `f943bcc` had been sitting unpushed; caught it up.
2. **Live-verify came back from the mbd agent** (has the live MCP connection). Results:
   - **#28 / #29 confirmed working** — #28's DB write was proven correct (added field fully linked); #29's guard/count signal fired as designed.
   - **#30 REOPENED** — the first fix was incomplete. It cleared `FormsService` + `FieldProvider`, but `Form::getLayout()` actually reads from **`LayoutsService`**, whose private plain-array memos (keyed by stable form id) nothing cleared. Same-session adds/reorders stayed invisible.
   - **#31 enriched** — deleting forms live proved `deleteById()` leaks orphans across 8 tables, not just submissions.
3. **Reshaped #31** from a `needs-human` "if delete_form is ever added…" note into a full `delete_form` feature spec (`enhancement` + `model:opus`, blocked-by #30).
4. **Ran nightshift `2026-07-21-2003`** (in-session Agent-tool path again — the nested-`claude` bypass is still classifier-denied). Two issues, dependency-ordered: **#30** (sonnet — extend `FreeformFormCacheReset` to flush `LayoutsService` arrays) then **#31** (opus — build `delete_form`), which unblocked in-run the moment #30 closed. Final **511 tests green, phpstan clean**.
5. **Merged `2026-07-21-2003`** to `main` (`6ac5375`, then STATE update `50e000c`), pushed. `delete_form` becomes the 67th live tool after a SIGHUP.

## Durable knowledge captured this wrap

- **The cache-staleness family is now a documented taxonomy** in [../architecture/freeform-integration.md](../architecture/freeform-integration.md#cache-staleness-family-in-the-long-running-server-26--28--29--30) — four sub-families keyed by *how the stale state is held* (container-singleton-Memo, container-singleton-plain-array, event-bound-instance, method-local-static), each with its own reset mechanism. Getting the mechanism wrong gives a silent false-fix (exactly how #30's first attempt passed review but failed live). The meta-lesson: **a Freeform write can report success with a correct DB while a same-session read still lies — verify against a fresh process, never only the same-session read.**
- **`delete_form`** documented (arch page + [implemented decision](../decisions/implemented/2026-07-23-delete-form-tool.md)): guarded delete + 8-table cascade cleanup + cache flush.

## #18 loose end — RESOLVED (was flagged 2026-07-16)

The prior wrap flagged that #18 (`get_form` notifications/connections/spam) showed closed-completed on GitHub while the wiki listed it open. Resolved: the fix is real and live-verified (2026-07-15) but **stranded in unmerged draft PR #27** off `worktree-issue-18-freeform-getform` — it is **NOT on `main`**. Not tied to upstream `stimmt`; the PR's bloated diff was a branch-timing artifact (based on a local `main` that was then 7 commits ahead of origin). Today it reduces to one real commit (`429ae4d`). Backlog + arch page updated to "fixed but UNMERGED"; merge PR #27 (rebased) to actually ship it.

## Housekeeping

- `.gitignore` now excludes `/.claude/` + `/.worktrees/` — `.claude/` was surfacing local settings *and* the nested stale `issue-18-freeform-getform` worktree as untracked noise. `nightshift-runs/` logs backfilled into git.
- Merged branch `nightshift/2026-07-21-2003` left in place (user's call), deletable.

## Still pending

- **Live-verify #30 (complete) + #31 (`delete_form`)** after SIGHUP — `docs/live-verify-handoff-2026-07-22.md`. Both merged but exercised only by boot-free unit tests (MCP was disconnected during the run).
- **Merge PR #27** to land the #18 `get_form` fix.
- #16 (`needs-human`) triage; the stale `.claude/worktrees/issue-18-freeform-getform` worktree cleanup.

## Cross-references

- [../architecture/freeform-integration.md](../architecture/freeform-integration.md)
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../decisions/implemented/2026-07-23-delete-form-tool.md](../decisions/implemented/2026-07-23-delete-form-tool.md)
- [2026-07-16-nightshift-run-and-handoff.md](2026-07-16-nightshift-run-and-handoff.md) — the prior wrap this continues
