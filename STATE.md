# STATE — craft-mcp-2rm

**Updated:** 2026-07-16 (nightshift run `2026-07-15-1125` MERGED to main)
**Status:** Active — code merged; live-verify pending (mbd agent), one known bug #28

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). Nightshift run `2026-07-15-1125` (9 issues) is **merged to `main`** (merge `3c169de`, HEAD `de688c4`, pushed; 472 tests green, phpstan clean). Worktree removed. A live-verification handoff doc (`docs/live-verify-handoff-2026-07-15.md`) is ready for the mbd agent (has the live MCP connection). One known open bug surfaced from live use: **#28** — `update_form`/`create_form` add-field leaves the field orphaned (`rowId` null + blank layout-row `uid`) → not rendered in CP despite success response.

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

## Next steps

- [ ] **mbd agent runs `docs/live-verify-handoff-2026-07-15.md`** against live MCP after SIGHUP — verifies persisted layout (not tool response), files issues (`bug`/`enhancement` + `ready-for-agent` + `model:<tag>`) for failures. Covers #19/#20/#22/#23/#26 handoffs + pins #28 scope.
- [ ] **Fix #28** (orphaned `rowId` + blank layout-row `uid` on add-field path) — pin scope first via the handoff's B1 disambiguator; likely opus.
- [ ] Optional: run `/ce-code-review` on the merged Freeform/Neo write code (deferred this wrap — per-issue verify + live-verify handoff cover most of it).
- [ ] Commit + push `docs/live-verify-handoff-2026-07-15.md` and this wrap's wiki/STATE updates.
- [ ] Optional: delete merged branch `nightshift/2026-07-15-1125` (local + origin). Commit nightshift SKILL.md (personal repo, still uncommitted).
- [ ] Flagged: #18 (`get_form` notifications) is CLOSED-completed on GH but wiki still lists it open — confirm whether its fix actually landed in `main`.

## Open questions

- Migrate fork to a versioned dependency (Packagist/VCS tag) vs the current symlink path repo?
- #18 (get_form) already closed as completed — the earlier "highest-value remaining" note was stale.
