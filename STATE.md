# STATE — craft-mcp-2rm

**Updated:** 2026-07-15 (mid nightshift run `2026-07-15-1125`)
**Status:** Active — nightshift AFK run PAUSED with 3 issues remaining

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). `main` is caught up + pushed (merged prior nightshift `2026-07-15-0353` = #13/#14). A new nightshift run is IN PROGRESS on branch `nightshift/2026-07-15-1125`, paused near a session limit.

### Nightshift run `2026-07-15-1125` (branch pushed to origin, 5/8 done)

Run agents dispatched via the **in-session Agent tool** (NOT nested `claude --print` — the bypassPermissions spawn is denied by the auto-mode classifier; user chose the Agent-tool path). Model per issue's `model:` label. Orchestrator (main session) verifies tests+HEAD, pushes, closes.

Done + closed (branch HEAD `ccedc6b`, tests 454 green, phpstan clean):
- #15 fable — pint-format src/tools (`90bc932`)
- #19 opus — `create_form` Freeform write tool + 28 tests (`e285fdd`); **live create+submission verify handed off** (needs SIGHUP)
- #21 sonnet — upload_asset GCS live-QA: **PASS**, no defect (`fb683ce`)
- #22 opus — **real bug fixed**: NeoBlockTree treated leaf `childBlocks: null` as allow-any → illegal nesting saved; fix + tests (`c79e32f`); live re-verify handed off
- #23 fable — echo real IDs via re-read-and-diff + 5 tests (`ccedc6b`); live echo verify handed off

## Next steps

- [ ] RESUME nightshift `2026-07-15-1125` at #24 (still `ready-for-agent`): #24 fable (create_block_type stub honor childBlockTypes, pure logic), #25 fable (cast Yii count() to int, pure logic), #26 opus (process-static cache staleness audit, code-half + live handoff).
- [ ] Worktree intact at `.worktrees/nightshift/2026-07-15-1125` (composer deps installed; baseline health-check = run `composer test`, expect 454). `LAST_GOOD_SHA=ccedc6b`. Run log: `nightshift-runs/2026-07-15-1125.md`.
- [ ] After the queue: skill steps 6–8 — file any fix_plan.md items (currently empty), `/wrap` from repo ROOT (not worktree), then `git worktree remove`.
- [ ] SIGHUP-reload mbd's MCP server + live-verify the handed-off items (#19 create_form end-to-end, #22 leaf-rejection, #23 id echo).
- [ ] QA + merge `nightshift/2026-07-15-1125` into `main`.
- [ ] Commit nightshift SKILL.md changes (personal tooling repo, still uncommitted).

## Resume recipe (fresh session)

1. `cd _plugins/craft-mcp-2rm`; read this file + `nightshift-runs/2026-07-15-1125.md`.
2. For each of #24/#25/#26: label `in-progress`, dispatch an Agent (model per label) pointed at the worktree, verify tests+HEAD advanced, push branch, close issue with a verification note. Same pattern as the 5 done. Env caveat: worktree code is NOT deployed to the running MCP server — live verification of code changes is handed off, not done in-run.

## Open questions

- Migrate fork to a versioned dependency (Packagist/VCS tag) vs the current symlink path repo?
- #18 (get_form) already closed as completed — the earlier "highest-value remaining" note was stale.
