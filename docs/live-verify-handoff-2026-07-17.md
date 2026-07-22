where# Live-Verification Handoff — craft-mcp-2rm nightshift run `2026-07-16-1238`

**For:** the agent running against the **live mbd install** (you have an active `craft-mcp` MCP connection + Craft CP access). This is the half of the run that could NOT be done in the isolated worktree.

**Your job:** run the test scenarios below against the live MCP tools, **verify each fix actually works in the persisted/rendered state** (not just that the tool returned OK), and **file / update GitHub issues for anything that fails.**

**What this run fixed (merged to `main` @ `1ca05ad`):** three bugs, all the same *"long-running MCP process serves stale in-process Freeform caches"* family. All fixes **reset or route around** an in-process cache. That framing matters for what counts as a pass — see "What a pass means" below.

| # | Symptom (before) | Fix | File |
|---|---|---|---|
| #28 | `update_form` add-field left orphaned (`rowId` null) → not rendered, tool still reported success | Reset Freeform's **event-bound** `LayoutPersistence` memo before persist | `src/support/FreeformLayoutCacheReset.php` |
| #29 | `get_submission`/`list_submissions`/count/delete crash (`Undefined array key <formId>`) for a form created **this session** | `SubmissionQuery` statics are method-local (unresettable) → **detect the crash and return an actionable "reload (SIGHUP) required" error** instead of crashing; count degrades to same signal | `src/support/FreeformStaleFormCache.php` |
| #30 | `get_form` served the **pre-mutation** field list after `update_form` remove/rename/reorder, same session; survived `clear_caches` | Shared `FreeformFormCacheReset` clears `FormsService` + `FieldProvider` memos on the persist path | `src/support/FreeformFormCacheReset.php` |

All three route through `FreeformScaffoldTools::triggerPersist()` (the one path `create_form` + `update_form` share), so both write tools flush.

---

## Prerequisites

1. **The mbd MCP server must be SIGHUP-reloaded so it runs the merged `main` (`1ca05ad`).** The code is merged but a running server executes the *old* process until SIGHUP. `reload_mcp` will NOT pick this up — it only detects newly-installed plugins, not code changes. If you cannot trigger the SIGHUP yourself, **stop and ask the human to restart the server**, then continue.
   - Sanity check you're on new code: after the restart, run the #30 scenario — if `get_form` still goes stale, the reload didn't take.
2. Targeting **Craft 5.10.5 / Neo 5.5.10 / Freeform 5.15.16** on mbd (`mbd.ddev.site`).

## What a pass means (read before starting)

- **The tool's `success` response does NOT count as a pass.** #28's whole lesson: a write returned OK while the CP rendered nothing. A scenario passes ONLY when the persisted/rendered state is correct.
- **The render is the gate; the DB is the diagnosis.** For each write, the pass decision is the behavioral check; the `run_query` checks exist to explain *why* it failed, not to override a failing render.
  - **PASS GATE (render):** `get_form` reflects the change AND `Freeform::getInstance()->forms->getFormById(<id>)->getLayout()->getFields()` (via `tinker`) matches — that's what the CP assembles.
  - **DIAGNOSIS (DB):** `run_query` the backing tables to confirm the record is fully linked (not orphaned).
- **These are cache-reset fixes → expect the bug to be GONE, not merely better-handled.** Because the fix resets the live in-process caches, the *same-session* stale read/write should now be correct on the first try — you should NOT need a SIGHUP mid-scenario to get the right answer. If you still see staleness within a session, the reset isn't reaching the live cache → that's a real failure, file it.
  - The one exception is #29's fallback path — see D3.
- **Scratch only + clean up.** Create disabled/scratch forms. **Hard-delete everything you create** at the end (Freeform makes a per-form table — see Cleanup). Report any id you couldn't remove.
- **gh repo is the FORK:** always `gh ... --repo Two-Rivers-Marketing/craft-mcp-2rm` (gh defaults to the wrong upstream).

## When a scenario fails → file (or update) an issue

- These three issues are **already closed** (fixes merged). If a scenario **passes**, do nothing — just report it passed.
- If a scenario **fails**, the fix regressed or was wrong: **reopen the corresponding issue** (`gh issue reopen <#> --repo Two-Rivers-Marketing/craft-mcp-2rm`) and comment with evidence. Only file a **new** issue for a *different* defect than #28/#29/#30.
- New-issue body must include: exact MCP call + args; what the tool reported vs. what the CP/DB actually shows; the `run_query` output proving the discrepancy; repro steps on mbd; any DB workaround you found (don't leave a manual patch on a scratch entity you're deleting).
- **Labels (required on every NEW issue):** `bug` (or `enhancement`), `ready-for-agent`, AND exactly one `model:<name>`:
  - `model:fable` — trivial/mechanical (a cast, string tweak, one-line link).
  - `model:sonnet` — standard fix on an established pattern (reuse an existing helper).
  - `model:opus` — cross-cutting, subtle, or needs live reverse-engineering of Freeform internals (the cache-family fixes were all opus/sonnet).
  - Create a label if missing: `gh label create "model:opus" --repo Two-Rivers-Marketing/craft-mcp-2rm --color 5319E7 2>/dev/null` (fable `8250DF`, sonnet `BFD4F2`).
- **Tell the human the issue URL** (their standing preference).

---

## Test scenarios

Do them in order — B and C reuse forms created in A.

### A. Setup baseline (used by B and C)
- **A1 — create a scratch form.** `create_form` name `zzVerify0716` with fields: `text` (label "Full name"), `email`, `textarea` (label "Bio"), `number` (label "Age"), `checkbox` (label "Subscribe"). `dryRun: false`.
  - Confirm it appears in `list_forms` and `get_form` **same session** (baseline #26 freshness). Note the form id + handle.
  - If it does NOT appear same-session → #26 regressed, reopen/file.

### B. #28 — `update_form` add-field is linked and renders
- **B1 — add a plain `text` field.** `update_form` adding `text` "Nickname". 
  - PASS GATE: `get_form` + `getLayout()->getFields()` include "Nickname".
  - DIAGNOSIS: `run_query` `SELECT id,type,rowId FROM craft_freeform_forms_fields WHERE ...` → new field's **`rowId` is NOT null**; and the referenced `craft_freeform_forms_rows` row exists with a **valid non-empty `uid`** on this form's layout.
  - This was the pinned #28 case (add path, ANY type). **Expected PASS now.** If `rowId` is null / field missing → #28 regressed, reopen.
- **B2 — add a `dropdown`.** `update_form` adding `dropdown` "Favorite apple" options `red, green, sour, purple`.
  - Same pass gate + diagnosis. Also confirm the option values persisted (`optionConfiguration`).
  - **Expected PASS.** (Original report suspected options-specific; live-verify pinned it as universal — a dropdown should now link like any field.)

### C. #30 — `get_form` reflects `update_form` mutations same-session
Use the form from A1 (now has extra fields from B).
- **C1 — remove.** `update_form` omitting one existing field (e.g. "Age"). PASS = `get_form` **same session** shows it gone AND ground-truth `craft_freeform_forms_fields` has no row for it. FAIL = `get_form` still lists it (the stale-layout bug).
- **C2 — rename.** Rename a field's label. PASS = `get_form` reflects the new label same-session.
- **C3 — reorder.** Reorder the remaining fields. PASS = `getLayout()` field order matches the requested order same-session.
- For all three: the fix resets `FieldProvider`'s memo on write, so **no SIGHUP should be needed** to see the fresh layout. Needing a restart = failure.

### D. #29 — submission read/count on a same-session form does not crash
- **D1 — submit.** Save a submission to the A1 form (via the form's normal submit path, or insert into its content table if you must). Confirm the row lands in `craft_freeform_submissions_<handle>_<id>`.
- **D2 — read.** `get_submission {id}` and `list_submissions {formHandle}` for the A1 form (created THIS session — the exact trigger).
  - **Expected PASS = returns the submission data, no `Undefined array key` crash and no "reload required" error.** The create-path cache reset should keep `SubmissionQuery`'s map from going stale for tool-created forms.
  - If you get an uncaught `Undefined array key` crash → the guard isn't wrapping that path, reopen #29.
- **D3 — count.** `list_forms` → the A1 form's `submissionCount` is a real integer, not silent `null`.
- **D4 — the fallback signal (only if D2 doesn't already read clean).** If a form somehow still hits the stale static (e.g. one created by a *different* process, not via these tools), the tool must return the clear **"MCP reload required (SIGHUP)"** error, NOT an uncaught crash. This is the graceful-degradation path — a clean actionable error here is a PASS for the guard even though the underlying vendor staleness remains. Note in your report whether you exercised this path at all.

---

## Cleanup checklist (do last)
- [ ] Hard-delete every scratch form created (A1 and anything else). **Also confirm the per-form submissions table is dropped** — Freeform makes one table per form (`craft_freeform_submissions_<handle>_<id>`). `run_query` `information_schema.tables` for leftover `craft_freeform_submissions_*` matching your scratch handles; report any orphaned table you can't drop. (Orphaned `craft_freeform_submissions` *rows* after a form delete are tracked in #31 — note if you hit that.)
- [ ] Confirm each scratch form is gone (`get_form` not-found; `run_query` 0 rows).
- [ ] Revert any manual DB patch you applied while investigating (delete the scratch entity instead of leaving hand-patched state).
- [ ] Report: which scenarios PASSED, which FAILED (with issue URLs), and any scratch you couldn't remove.

---

## Context
This run resolved the in-process-cache family into a taxonomy by *how the stale thing is held* (relevant if you file a new stale-cache issue):
- **event-bound instance** (container hands back an unbound one) → reach the live one via the Yii event registry [#28].
- **method-local `static`** (inside a method body) → NOT reachable by reflection at all → detect the resulting crash and signal reload [#29].
- **container singleton** → `\Craft::$container->get(...)` returns the live instance → reflection-reset its private memo directly [#30].

Prior handoff `docs/live-verify-handoff-2026-07-15.md` covered the earlier run (#19/#20/#22/#23/#26) — its Freeform-layout "pass gate" notes still apply here.
