# Live-Verification Handoff — craft-mcp-2rm nightshift run `2026-07-21-2003`

**For:** the agent running against the **live mbd install** (active `craft-mcp` MCP connection + Craft CP/DB access). This run's agents worked with the MCP **disconnected**, so nothing below has been exercised live — that's your job.

**Your job:** run the scenarios against the live MCP tools, **verify the persisted/rendered/DB state is correct** (not just that the tool returned OK), and **reopen the issue + comment with evidence** for anything that fails.

**What this run delivered (branch `nightshift/2026-07-21-2003`, HEAD `948ffbc` — NOT yet merged):**

| # | Fix | File |
|---|---|---|
| #30 | Completed the earlier incomplete fix: flush `LayoutsService`'s private array memos (`pages/layouts/rows/formLayouts`) — the memo `Form::getLayout()` actually reads | `src/support/FreeformFormCacheReset.php` |
| #31 | New **`delete_form`** tool — guarded delete + full 8-table orphan-cascade cleanup + cache flush | `src/tools/FreeformScaffoldTools.php`, `src/support/FreeformFormDeletionCascade.php` |

---

## Prerequisites

1. **Merge or check out branch `nightshift/2026-07-21-2003`, then SIGHUP-restart the MCP server** so it runs this code. `reload_mcp` will NOT pick it up (it only detects new plugins). If you can't restart it, stop and ask the human.
   - Confirm new code is live: `list_mcp_tools` must now show **`delete_form`** (the #31 tool). If it's absent, the reload didn't take.
2. Targeting **Craft 5.10.5 / Freeform 5.15.16** on mbd (`mbd.ddev.site`).

## What "pass" means

- **Tool `success` ≠ pass.** A scenario passes only when the persisted/rendered/DB state is correct.
- **Render is the gate, DB is the diagnosis.** For layout reads: `get_form` AND `Freeform::getInstance()->forms->getFormById(<id>)->getLayout()->getFields()` (via `tinker`) must agree — that's what the CP assembles.
- **These are cache-reset / cleanup fixes → expect the bug GONE same-session, no mid-scenario SIGHUP.** Needing a restart to get the right answer = failure.
- **Scratch only + clean up.** Create disabled/scratch forms; hard-delete everything at the end (and #31 IS a delete tool — you can dogfood it for cleanup, but verify it first). Report anything you couldn't remove.
- **gh repo is the FORK:** always `gh ... --repo Two-Rivers-Marketing/craft-mcp-2rm`.

## When a scenario fails
Both issues are **closed** (fixes merged/branched). A pass = do nothing but report it. A failure = **reopen the issue** (`gh issue reopen <#> --repo Two-Rivers-Marketing/craft-mcp-2rm`) and comment with: exact MCP call + args, tool response vs. actual DB/render, the `run_query` output proving it, repro steps. Only file a NEW issue for a genuinely different defect (labels: `bug`/`enhancement` + `ready-for-agent` + one `model:<name>`; tell the human the URL).

---

## Scenario 1 — #30: `get_form` reflects `update_form` mutations same-session

This is the exact case the earlier fix missed (adds/reorders were invisible; the stale read only bites when the layout was **read before** the write, which populates `LayoutsService::$rows[formId]`).

1. `create_form` a scratch form (`zzVerify0722`) with ~4 fields.
2. **`get_form`** it once (this is what froze the stale cache before).
3. `update_form` **adding** a `text` field "Nickname".
   - **PASS:** `get_form` **same session** now includes "Nickname", AND `getLayout()->getFields()` includes it. FAIL = still missing (the #30 regression).
4. `update_form` **reordering** the fields.
   - **PASS:** `getLayout()` field order matches the requested order same-session. (Reorder was the other invisible op.)
5. `update_form` **removing** a field and **renaming** another — both should reflect same-session too.
6. Ground-truth spot check: `run_query` `craft_freeform_forms_fields WHERE formId=<id>` matches what `get_form` returns.

No SIGHUP anywhere between steps 2–6. If any read is stale until a restart → reopen **#30**.

## Scenario 2 — #31: `delete_form` (guard A / cascade B / cache C)

Tool signature: `delete_form(handle?, id?, dryRun=false, confirm?)`. Match by `id` OR `handle`. `dangerous:true`.

**2a — dryRun preview (no bare-delete).**
- `delete_form {handle:"zzVerify0722", dryRun:true}` → returns `{dryRun, form:{id,handle}, submissions, contentTable, wouldDelete:{table→count}}` and **persists nothing** (form still in `list_forms`).
- `delete_form {id:<id>}` with **no `confirm`** → must **throw / refuse**, delete nothing. (Confirm-by-handle gate.)

**2b — seed a submission, then real delete (cascade B).**
- Save ≥1 submission to the form (normal submit or insert into `craft_freeform_submissions_<handle>_<id>`).
- `delete_form {handle:"zzVerify0722", confirm:"zzVerify0722"}` (confirm === exact handle).
- **PASS gate:** return payload has `orphansClean: true` and every `orphansRemaining` count is 0. Then **independently verify** — `run_query` for 0 rows in each, keyed as noted:

  | table | key |
  |---|---|
  | `craft_freeform_forms_fields` | `formId` |
  | `craft_freeform_forms_rows` | `formId` |
  | `craft_freeform_forms_pages` | `formId` |
  | `craft_freeform_forms_layouts` | `formId` |
  | `craft_freeform_submissions` (meta) | `formId` |
  | `craft_searchindex` | `elementId` (submission elements) |
  | `craft_elements_sites` | `elementId` |
  | `craft_elements` | `id` |

- Also confirm the per-form content table `craft_freeform_submissions_<handle>_<id>` was **dropped** (`information_schema.tables`).
- FAIL = any orphan row remains, or the payload claims clean while the DB disagrees → reopen **#31** with the table + counts.

**2c — cache flush after delete (C).**
- Same session, no restart: `list_forms` and `get_form {handle}` must **no longer return** the deleted form. FAIL (still listed, e.g. with `submissionCount: "reload_required"`) → reopen **#31**.

**2d — same-session create→delete crash guard (A).**
- In one session: `create_form` a fresh scratch form → immediately `delete_form` that same form.
- **PASS:** returns success (cascade clean) OR the actionable **"MCP reload required (SIGHUP)"** message — **never** an uncaught `Undefined array key` from Freeform's `SubmissionQuery`. An uncaught crash → reopen **#31**.
- (This is the #29-family static that can't be reset — a clean actionable error here is a PASS for the guard.)

## Cleanup
- [ ] Delete every scratch form (dogfood `delete_form`, or delete + verify cascade manually). Confirm no leftover `craft_freeform_submissions_*` tables for your scratch handles.
- [ ] Revert any manual DB patch you applied while investigating.
- [ ] Report: which scenarios PASSED / FAILED (with issue URLs), and anything you couldn't clean.

---

## Context
This run continues the "long-running MCP serves stale in-process Freeform caches" taxonomy. #30 added the fourth sub-family: **container-singleton-with-plain-array-memo keyed by stable id** (`LayoutsService` — reachable, but reflection-empty the arrays; no `Memo::clear()`). #31's `delete_form` closes the write-CRUD surface (create/update/delete) and depends on #30's LayoutsService flush for its post-delete cache reset. Prior handoffs: `docs/live-verify-handoff-2026-07-15.md` (#19/#20/#22/#23/#26), `docs/live-verify-handoff-2026-07-17.md` (#28/#29/#30-first-attempt).
