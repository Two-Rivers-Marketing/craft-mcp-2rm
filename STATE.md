# STATE — craft-mcp-2rm

**Updated:** 2026-07-22 (nightshift run `2026-07-21-2003` — #30 completion + `delete_form`, on branch, unmerged)
**Status:** Active — branch `nightshift/2026-07-21-2003` pushed (511 tests green, phpstan clean); **live-verify + merge pending** (MCP was disconnected this run)

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools).

**Nightshift run `2026-07-21-2003` — 2/2 done, on branch `nightshift/2026-07-21-2003` (HEAD `948ffbc`, pushed, NOT merged; 511 tests green, phpstan clean):**
- **#30** (sonnet, `b81f6bc`) — the prior #30 fix was incomplete (live-verify reopened it). `Form::getLayout()` reads from `LayoutsService` (`->formLayouts`), whose private plain-array memos (`pages/layouts/rows/formLayouts`, keyed by stable form id) nothing cleared → same-session adds/reorders stayed invisible. Fix extends `FreeformFormCacheReset` to reflection-empty those arrays. New taxonomy sub-family: **container-singleton-with-plain-array-memo keyed by stable id** (array-null, not `Memo::clear()`).
- **#31** (opus, `948ffbc`) — new **`delete_form`** tool, completing Freeform write CRUD. Guarded delete (A, reuses `FreeformStaleFormCache::guard`) + full 8-table FK-safe orphan-cascade cleanup with 0-orphan assertion (B) + `FreeformFormCacheReset::reset()` on the delete path (C). Match by id/handle, `dryRun` preview, **confirm-by-handle** gate, `dangerous:true`. `#31` was reshaped from a needs-human note into this spec earlier same session.

**Neither is live-verified** (the `craft-mcp` MCP was disconnected this run → agents worked from vendor source + the reopened-#30 live diagnosis). Live-verify hand-offs are in each issue's closing comments / the agents' `progress.md`.

**Scratch files (`progress.md`, `fix_plan.md`) are tracked on this branch** — drop them at merge (prior convention, `de688c4`).

### Prior run `2026-07-16-1238` (MERGED, `1ca05ad`)

**Nightshift run `2026-07-16-1238` — 3/3 done, on branch `nightshift/2026-07-16-1238` (HEAD `4b01bb3`, pushed, NOT merged; 494 tests green, phpstan clean).** All three were live-verify bugs, all the same "long-running MCP process serves stale in-process Freeform caches" family as the earlier #26 fix — resolved by the run into a coherent taxonomy:

- **#28** (opus, `5979147`) — `update_form` add-field orphaned. Real cause was NOT a missing row (payload already builds one) but a stale memo in Freeform's **event-bound** `LayoutPersistence` instance. Fix: `src/support/FreeformLayoutCacheReset.php` resets it via the Yii event registry (container returns an unbound instance), called from shared `triggerPersist()`.
- **#29** (opus, `b97404d`) — `get_submission`/`list_submissions`/count/delete crash (`Undefined array key`) for a form created this session. Cause: `SubmissionQuery` **method-local statics** — NOT resettable via reflection, only SIGHUP. Fix: `src/support/FreeformStaleFormCache.php` detects the vendor crash and returns an actionable "reload (SIGHUP) required" error instead of crashing; count degrades to same signal.
- **#30** (sonnet, `4b01bb3`) — `get_form` serves stale field layout after `update_form` mutation. Cause: `FieldProvider`'s per-form memo (**container singleton** — reflection works directly). Fix: `src/support/FreeformFormCacheReset.php` — replaces #26's `flushFormCache()`, clears both `FormsService` + `FieldProvider` memos, wired into the one shared `triggerPersist()` path so create + update both flush.

**Cache sub-family taxonomy learned this run** (reset mechanism depends on how the stale thing is held): (a) event-bound instance → reach via Yii event registry [#28]; (b) method-local static → unreachable, detect+signal only [#29]; (c) container singleton → reflection reset directly [#30].

**None of the three is live-verified** — the worktree runs symlinked `main` and needs a manual SIGHUP; only the mbd agent with the live MCP connection can confirm end-to-end. That is the open gate before merge.

Prior run `2026-07-15-1125` (9 issues) remains **merged to `main`** (HEAD before this run: `f943bcc`).

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

- [x] ~~Merge branch `nightshift/2026-07-16-1238`~~ — DONE 2026-07-17 (`1ca05ad`, pushed; scratch files dropped; merged branch deletable).
- [ ] **LIVE-VERIFY** (#28/#29/#30) — merged to main; **SIGHUP the MCP server** (picks up the merged code — `reload_mcp` won't; it only detects new plugins), then run against live mbd:
  - #28: `create_form` → `update_form` add-field → confirm new field gets non-null `rowId` and renders in CP.
  - #29: `create_form` this session → save submission → `get_submission`/`list_submissions`/`list_forms` count → confirm no crash and the reload-required error only appears when actually stale (ideally the reset fixes make it not stale at all).
  - #30: `create_form` → `update_form` remove/rename/reorder → `get_form` reflects the mutation same-session (no stale layout).
  - These fixes RESET in-process caches, so the expectation is the stale reads/writes are now fixed live (not just a better error). Verify the reset actually works against the live long-running server — that's the whole point.
- [ ] Drop scratch `progress.md` from the branch at merge (matches prior run's `de688c4` convention). `fix_plan.md` was untracked (removed with worktree).
- [ ] #31 (`needs-human`) filed: constraints for a future `delete_form` tool — no action unless/until that tool is built.
- [ ] Optional: run `/ce-code-review` on the branch's Freeform cache-reset code (reflection + event-registry access is subtle) before merge.
- [ ] Optional: delete merged branch `nightshift/2026-07-15-1125` (local + origin).
- [ ] Flagged (carried over): #18 (`get_form` notifications) CLOSED-completed on GH but wiki listed it open — confirm its fix landed in `main`.

## Open questions

- Migrate fork to a versioned dependency (Packagist/VCS tag) vs the current symlink path repo?
- #18 (get_form) already closed as completed — the earlier "highest-value remaining" note was stale.
