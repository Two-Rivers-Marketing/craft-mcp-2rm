# STATE — craft-mcp-2rm

**Updated:** 2026-07-15 (nightshift run `2026-07-15-1125` COMPLETE)
**Status:** Active — nightshift run done; branch awaiting QA + live-verify + merge

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). `main` caught up + pushed (merged prior nightshift `2026-07-15-0353` = #13/#14). Nightshift run `2026-07-15-1125` is COMPLETE — all 9 queued issues implemented, verified (tests+phpstan), pushed, and closed on branch `nightshift/2026-07-15-1125` (NOT yet merged to main).

### Nightshift run `2026-07-15-1125` — 9/9 done (branch HEAD `731a7d4`, 472 tests green, phpstan clean)

Agents dispatched via the **in-session Agent tool** (NOT nested `claude --print` — the bypassPermissions spawn is denied by the auto-mode classifier; Agent-tool path chosen). Model per issue's `model:` label. Orchestrator (main session) verified tests+HEAD, pushed, closed each.

- #15 fable — pint-format src/tools (`90bc932`)
- #19 opus — `create_form` Freeform write tool + 28 tests (`e285fdd`) — live create+submission verify HANDED OFF
- #21 sonnet — upload_asset GCS live-QA: **PASS**, no defect (`fb683ce`)
- #22 opus — **real bug fixed**: NeoBlockTree treated leaf `childBlocks: null` as allow-any → illegal nesting saved (`c79e32f`) — live re-verify handed off
- #23 fable — echo real IDs via re-read-and-diff + 5 tests (`ccedc6b`) — live echo verify handed off
- #24 fable — stub children loop honors childBlockTypes + 3 tests (`ea32ef2`)
- #25 fable — cast Yii count() to int, 2 response sites (`103c018`)
- #26 opus — cache-staleness audit; **found + fixed** create_form leaving FormsService Memo stale → `flushFormCache()` (`315e034`/`d6239d0`) — live re-verify handed off
- #20 sonnet — `update_form` (add/remove/reorder fields, UID-preserving, dryRun diff) + 15 tests (`731a7d4`) — armed mid-run after #19 landed; live-verify handed off

## Next steps (all post-run, none auto-done)

- [ ] **SIGHUP-reload mbd's MCP server**, then live-verify the handed-off items in ONE session: #19 create_form end-to-end (create + submission), #20 update_form (add/remove/reorder + post-edit submission read), #22 leaf-nesting rejection, #23 id echo, #26 create_form→get_form-by-handle freshness.
- [ ] QA + **merge `nightshift/2026-07-15-1125` into `main`** (11 commits; branch not yet merged).
- [ ] Skill wrap steps not run this session: `/wrap` (code review + compound + wiki digest) and `git worktree remove .worktrees/nightshift/2026-07-15-1125`. Worktree left intact deliberately. fix_plan.md was empty — no discovered-work issues filed.
- [ ] Commit nightshift SKILL.md changes (personal tooling repo, still uncommitted).

## Open questions

- Migrate fork to a versioned dependency (Packagist/VCS tag) vs the current symlink path repo?
- #18 (get_form) already closed as completed — the earlier "highest-value remaining" note was stale.
