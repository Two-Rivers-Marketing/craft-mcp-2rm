---
type: log
timestamp: 2026-07-16
---

# 2026-07-16 — nightshift run `2026-07-15-1125`, merge, and live-verify handoff

**Operation:** log (session wrap). **Scope:** whole-run orchestration narrative + gaps not captured by the per-issue logs written during the run.

## What happened

Caught `main` up (merged prior nightshift `2026-07-15-0353` = #13/#14, pushed), then ran a full nightshift AFK run `2026-07-15-1125` covering **9 issues** — #15, #19, #20, #21, #22, #23, #24, #25, #26 — each verified per-issue (`composer test` + `phpstan --memory-limit=1G`), pushed, and closed. Final **472 tests green**. Branch merged to `main` (merge `3c169de`, HEAD `de688c4`) and pushed; run-scratch files (`progress.md`, `fix_plan.md`) dropped from main.

Two real defects fixed (not just features): **#22** (`NeoBlockTree` treated a leaf type's `childBlocks: null` as allow-any → illegal nesting saved) and **#26** (`create_form` left Freeform's `FormsService` memo cache stale → `flushFormCache()`). Two new Freeform write tools shipped: **`create_form`** (#19) and **`update_form`** (#20, UID-preserving so submissions survive edits).

## Orchestration note (how the run was done)

The nightshift skill's AFK mechanism spawns each issue's agent as a nested `claude --print --permission-mode bypassPermissions` process. This session's auto-mode permission classifier **denied** that unrestricted spawn. Pivoted to the **in-session Agent tool** instead — same per-label model delegation (`model:` label → Agent `model` param: fable/sonnet/opus), same context isolation, but actions stay under the session's normal permission gating. All 9 issues ran this way successfully. Open question: reconcile the skill's bypass design with the classifier (a settings allow-rule) or make the Agent-tool path the default.

## The recurring gap (why the handoff doc exists)

The Freeform/Neo **write** tools ship unit-tested + phpstan-clean, but worktree/subagent runs structurally **cannot live-verify write behavior** against real Freeform/Neo (their new code isn't on the running MCP server; boot-free unit tests can't exercise Freeform's layout-persistence linkage). This bit us immediately: **#28** — adding a dropdown via `update_form` left the field orphaned (`rowId` null) *and* created a layout row with a blank `uid` (dropped by `LayoutsService`) → CP rendered nothing while the tool reported success. It surfaced only from live CP use, **after** merge.

Response: wrote `docs/live-verify-handoff-2026-07-15.md` — a 10-test suite for the agent that HAS the live MCP connection, whose core rule is **"verify the persisted layout the CP renders, not the tool's success response,"** plus an issue-filing contract (`bug`/`enhancement` + `ready-for-agent` + a `model:<name>` tag). #28's root cause is now in [../architecture/freeform-integration.md](../architecture/freeform-integration.md) Known-still-broken.

## Staleness swept this wrap

Marked done in the QA backlog: Neo positioning edge cases (#22), response-facing `count()` casts (#25), process-static cache audit (#26). Filed #28 as a new open Freeform bug.

## Flagged (not auto-fixed)

- #18 (`get_form` notifications/connections/spam) shows **CLOSED as completed** on GitHub, but the wiki (freeform-integration Known-still-broken + backlog) still lists it as open/unfixed. No evidence its fix landed in `main` this session; a locked worktree `.claude/worktrees/issue-18-freeform-getform` exists. Needs a human call on whether #18 actually shipped.

## Cross-references

- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../architecture/freeform-integration.md](../architecture/freeform-integration.md)
- [../architecture/neo-integration.md](../architecture/neo-integration.md)
- [../decisions/implemented/2026-07-15-create-form-tool.md](../decisions/implemented/2026-07-15-create-form-tool.md), [../decisions/implemented/2026-07-15-update-form-tool.md](../decisions/implemented/2026-07-15-update-form-tool.md)
