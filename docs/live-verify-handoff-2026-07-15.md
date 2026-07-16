# Live-Verification Handoff — craft-mcp-2rm nightshift run `2026-07-15-1125`

**For:** the agent running against the **live mbd install** (you have an active `craft-mcp` MCP connection + Craft CP access). This is the half of the run that could NOT be done in the isolated worktree.

**Your job:** run the test suite below against the live MCP tools, **verify each test actually succeeded in the persisted layout / CP** (not just that the tool returned OK), and **file a GitHub issue for anything that fails or that you cannot do.**

---

## Prerequisites

1. The mbd MCP server has been **SIGHUP-reloaded** so it runs the merged `main` code. Confirm the new tools are live:
   - `list_mcp_tools` (or equivalent) must show `create_form` and `update_form`. If they're absent, the reload didn't take — stop and report.
2. You are targeting **Craft 5.10.5 / Neo 5.5.10 / Freeform 5.15.16** on mbd (`mbd.ddev.site`).

## Hard rules (read before starting)

- **The tool's success response does NOT count as a pass.** Issue #28 proved a write can report success while leaving the CP empty. A test passes ONLY when the persisted state that the CP renders is correct.
- **How to "verify in the CP" — the render is the gate, the DB is the diagnosis.** For each write, the pass/fail decision is **(1)**; the `run_query` checks in **(2)** exist to explain *why* (1) failed, not to override it. Confirm ALL of:
  1. **PASS GATE — behavioral render.** The field actually appears in the assembled layout: `get_form` reflects it AND `Freeform::getInstance()->forms->getFormById(<id>)->getLayout()->getFields()` includes its handle (this is what the CP renders). If it's not here, the test FAILS regardless of what the DB rows look like.
  2. **DIAGNOSIS — DB linkage.** `run_query` the backing tables and confirm the record is **fully linked**, not orphaned. #28 has TWO failure modes — check BOTH:
     - `craft_freeform_forms_fields.rowId` is **NOT null**, AND
     - the referenced `craft_freeform_forms_rows` row has a **non-empty, valid UUID `uid`** and belongs to the form's layout. **A correct `rowId` pointing at a blank-`uid` row still fails to render** — `LayoutsService::attachRows()` assembles the layout by row uid, so a blank-uid row is silently dropped. `rowId`-not-null alone is NOT sufficient and will give a false PASS.
  3. Where the human is watching, have them eyeball the actual CP page too.
- **Scratch only + clean up.** Create disabled/scratch entities (forms, a disabled `pages` entry, throwaway block types). **Hard-delete everything you create** at the end. Report any id you couldn't remove.
- **gh repo is the FORK:** always `gh ... --repo Two-Rivers-Marketing/craft-mcp-2rm` (gh defaults to the wrong upstream).
- **Known open bug #28 — scope is NOT yet pinned; do not assume it's "options-group."** Live repro found TWO failure modes on the add path: (a) new field written with `rowId = null` (orphaned), and (b) the new layout row created with an **empty `uid`**, which `LayoutsService` drops from the assembled layout even after `rowId` is fixed. Both were observed only on the **`update_form` add-a-dropdown** path — the create-with-dropdown path and the update-add-a-**non-dropdown** path were never exercised. So the "options-group" framing (and the #28 title) may be wrong: mode (b) is on *row creation for any added field* and could hit plain text adds too.
  - **The only options-group field type these tools support in v1 is `dropdown`.** multi-select / checkboxes / radios are NOT in the v1 type subset (`text, textarea, email, dropdown, checkbox, number`) — you cannot create them, so don't chase them. `checkbox` is a boolean single field, not an options group.
  - **Expect the dropdown tests to FAIL.** Do NOT file a duplicate — **comment on #28** with the scope you actually pin down (see B1 below, the disambiguator). Only file a NEW issue for a DIFFERENT failure.

## When a test fails or you can't run it → file an issue

File to `Two-Rivers-Marketing/craft-mcp-2rm` with:
- **Title:** `live-verify: <tool> <symptom>`
- **Body:**
  - What you ran (the exact MCP call + args).
  - What the tool reported vs. what the CP/DB actually shows.
  - The `run_query` output proving the discrepancy (e.g. the orphaned `rowId`).
  - Steps to reproduce on mbd.
  - Any DB-level workaround you found (do NOT leave a manual DB patch in place on a scratch entity you're about to delete).
- **Labels (required on every filed issue):**
  - `bug` (or `enhancement` if it's a missing capability),
  - `ready-for-agent` (so the next nightshift picks it up), AND
  - exactly one **`model:<name>`** tag so nightshift routes it to the right model. Pick by effort:
    - `model:fable` — trivial/mechanical, well-scoped (a cast, a string tweak, an obvious one-line link fix).
    - `model:sonnet` — standard implementation on an established pattern (a CRUD-on-layout fix reusing existing helpers).
    - `model:opus` — cross-cutting, subtle, or needs live reverse-engineering / design judgment (e.g. the #28 orphaned-`rowId` fix touches the Freeform layout-persistence internals across field types → opus).
  - If the label doesn't exist yet, create it: `gh label create "model:opus" --repo Two-Rivers-Marketing/craft-mcp-2rm --color 5319E7 2>/dev/null` (fable `8250DF`, sonnet `BFD4F2`).
- **Tell the human the issue URL before/after filing** (per their standing preference).

---

## Test suite

### A. Freeform `create_form` (#19) + cache freshness (#26)

- **A1 — dryRun previews, saves nothing.** `create_form` with `dryRun: true` (name + 2 text fields). Verify: NO new form in `list_forms` / CP. Pass = nothing persisted.
- **A2 — non-options field types render.** `create_form` a scratch form with `text`, `textarea`, `email`, `number`, `checkbox` fields. Verify in CP + `get_form`: form appears in the form list; **every** field is present in the layout; handles derive via `toHandle` (acronyms not mangled). Then submit a test submission and confirm it saves and reads back. This isolates the #28 bug from the base path — if these non-options fields DON'T render either, that's a bigger regression → new issue.
- **A3 — options-group field on the CREATE path (the #28 case).** Use **`create_form`** with a `dropdown` (options) field **in the initial spec** — do NOT add it via `update_form` (that's the B path; routing through update here defeats the point of isolating create). This path was never exercised live, so the answer is genuinely open. Verify per the pass gate whether the dropdown links + renders, and diagnose both #28 modes (`rowId` null? row `uid` blank?). Record on #28 whether **create** is affected or only update.
- **A4 — cache freshness (#26).** In the SAME MCP session, no restart: `create_form` a scratch form → immediately `get_form` **by handle** (must find it) → `list_forms` (must include it). Pass = both reflect the new form without a reload. (This verifies the `flushFormCache()` fix.)

### B. Freeform `update_form` (#20) + the #28 repro

Use a scratch form from A2 (has known submissions). **Run B1 before B2 — B1 is the disambiguator that decides how you scope #28.**

- **B1 — add a non-options field (THE #28 DISAMBIGUATOR — run this first).** Add a `text` field via `update_form`. Verify per the pass gate. Then check the DB for **both** #28 modes on the *new* field: is `rowId` set, and does its new row have a non-empty `uid`?
  - If B1 **renders fine** (rowId set, valid row uid) → the bug is dropdown/options-specific; proceed to B2 to confirm.
  - If B1 **fails the same way** (orphaned `rowId`, or blank row `uid` → not rendered) → **#28 is not an options-group bug at all, it's an `update_form` add-row bug.** This reframes the whole issue — say so explicitly in your #28 comment and correct the title framing. This is the single most important thing this suite can determine.
- **B2 — add a dropdown (the exact repro).** Add a `dropdown` "Select your favorite apple" with options `red, green, sour, purple`. Verify per the pass gate. **Expected FAIL per #28.** Diagnose via `run_query`: capture the new field's `rowId` AND its row's `uid` (both modes). Comment findings on #28, interpreted against B1's result (options-specific vs. universal).
- **B3 — remove a field.** Remove one field. Verify CP shows it gone, other fields intact, and existing submissions still read (the removed field's column handling didn't corrupt rows).
- **B4 — reorder fields.** Reorder the remaining fields. Verify the CP layout order matches the requested order.

### C. Neo positioning / nesting (#22) + id echo (#23)

Create ONE disabled scratch `pages` entry (section `pages`, type `pages`) to test on. Note its id.

- **C1 — illegal nesting rejected (#22).** Attempt each and confirm a **clear error + NO save**:
  - a top-level-only block type placed inside `columnItem`,
  - a child type NOT in the parent's `childBlocks`,
  - a child placed under a **leaf** block type (one whose `childBlocks` is `null`) — this is the exact bug #22 fixed.
  After each, read the tree via tinker (`benf\neo\elements\Block::find()->owner($entry)->orderBy('lft')`) and confirm `lft`/`rgt`/`level` integrity is intact (no partial/illegal block saved).
- **C2 — id echo (#23).** `create_neo_block` must return the **real integer block IDs** (not `null`) in creation order; `create_block_type` must return the **real block-type id**. Cross-check the echoed ids against the DB. Then re-read the tree and confirm placement.

## Cleanup checklist (do this last)

- [ ] Hard-delete every scratch form created (A2/B*) and their submissions. **Also confirm the per-form submissions table is dropped** — Freeform creates one table per form (`craft_freeform_submissions_<handle>_<id>`, observed live). `run_query` `information_schema.tables` for leftover `craft_freeform_submissions_*` matching your scratch handles; report any orphaned table you can't drop.
- [ ] Hard-delete the scratch `pages` entry (C) and any scratch block types created in C2.
- [ ] Confirm each is gone (`get_form`/`get_entry` returns not-found; `run_query` shows 0 rows).
- [ ] Revert any manual DB link you applied while investigating #28 (don't leave hand-patched state — delete the scratch entity instead).
- [ ] Report: which tests PASSED, which FAILED (with issue URLs), and any scratch you couldn't remove.

---

## Context: what this run delivered (branch merged to `main`, live after SIGHUP)

`create_form` + `update_form` (Freeform write tools, #19/#20), Neo leaf-nesting rejection (#22), real-id echo (#23), stub-loop fix (#24), count() casts (#25), and a cache-staleness audit + `flushFormCache()` fix (#26). All unit-tested (472 tests green) but the Freeform/Neo **write behavior against live Freeform/Neo was never exercised** — that's this suite. Issue **#28** (orphaned `rowId` on options-group fields) was filed after this run from a live repro and is the one known open defect; the suite is designed to pin down its full scope and to catch anything else.
