---
type: log
timestamp: 2026-08-11
---

# 2026-08-11 — landed the stranded #18 fix, made `list_entries` fields opt-in (v1.7.0)

**Operation:** log. **Scope:** resolved the two longest-standing open items — the `get_form` fix stranded in draft PR #27 since 2026-07-15, and the `list_entries` verbosity decision. Both shipped in v1.7.0 and verified on kcma.ddev.site.

## PR #27 was not merged — the one real commit was cherry-picked

**Decision: do not merge; cherry-pick and close.** The PR looked like a 21-file, +1004/-94 change. Commit-by-commit ancestry check against `origin/main`:

- **7 of 8 commits were already on `main`** (`6996896`, `cfedf88`, `54003bd`, `0fbdb9c`, `007cf70`, `6435b83`, `4debcdc`)
- **1 was missing** — `429ae4d`, the actual fix: 4 files, +288

The inflated diff was the branch-timing artifact the 2026-07-27 log predicted: the branch was cut from a local `main` that was ahead of `origin`. GitHub reported `mergeable: UNKNOWN`, and merging would have replayed already-landed work against three weeks of subsequent `FreeformTools`/`FreeformSerializer` change (the #28/#29/#30 cache work, `delete_form`).

Cherry-pick outcome (tested on a scratch branch first, not directly on `main`):

- All three **code** files auto-merged cleanly — `FreeformSerializer.php`, `FreeformTools.php`, `FreeformSerializerTest.php`.
- The only conflict was `docs/wiki/architecture/freeform-integration.md`, and it was lopsided: 177 lines on `main`'s side against 11 on the branch's. Resolved in favour of `main`, then the branch's `get_form` settings-sections content — which `main` genuinely lacked — was appended, and `main`'s now-false "FIXED but UNMERGED" bullet was corrected.

Landed as `d402bd8`. PR #27 closed as superseded with the reasoning; branch `worktree-issue-18-freeform-getform` is deletable.

**Live-verified on KCMA** (`get_form` id 1, `testForm`): `notifications`, `connections`, `spamSettings` all present as **lists**, none `null`. Issue #18's fix is finally real on `main` — it had been marked closed-completed on GitHub since 2026-07-15 while the code sat unmerged.

## `list_entries` — fields now opt-in (BREAKING)

Took option 1 from the [2026-08-10 decision](2026-08-10-new-tools-and-live-verification.md#open-decision--list_entries-verbosity). `list_entries` no longer returns custom field values; pass `includeFields: true` for the old shape, or use `get_entry` for one entry.

Live-measured on KCMA, same call before and after:

| | bytes | note |
|---|---|---|
| `list_entries(limit: 25)` — before / `includeFields: true` | 127,605 | 79% one SEOmatic MetaBundle field per entry |
| `list_entries(limit: 25)` — new default | 11,448 | **92% smaller, 116KB saved on a single call** |

Metadata (`id`, `title`, `slug`, `status`) and paging (`count`, `total: 743`, `limit`, `offset`) are intact. `get_entry` still returns all fields by design. `includeFields` was **appended, not inserted**, so positional callers do not shift — there is a test asserting that.

**This closes the field report's finding #2 properly.** The 2026-07-31 fix was scoped to the two tools the report named (`create_entry`/`update_entry`) rather than to the serializer all of them share. That is why `list_drafts` re-inherited the bug the moment it shipped (v1.6.1) and `list_entries` still had it. The durable lesson: **fix the shared serializer, or every new element-listing tool re-inherits the problem.**

Breaking for mbd, which is still on v1.5.0 and will pick this up on its next `composer update` — flagged in the v1.7.0 tag and commit.

## Found in passing — `list_forms` returns an object map, not an array

`list_forms` returns `forms` as a **dict keyed by form-id string** (`{"1": {...}}`) where every other
`list_*` tool returns a JSON array. Freeform's service hands back an id-keyed associative array and
`json_encode` renders that as an object.

Not fixed here — it is a shape change with unknown consumers, and it belongs with the field report's
existing "inconsistent identifier parameter" item rather than in this change. Recorded because it
cost real time twice today: it is what made two verification scripts die silently on `forms[0]`
(`KeyError: 0`), which read as a `get_form` failure rather than a client bug.

## Method note

Three verification scripts failed on this shape before the cause was clear, each time looking like a
server problem. Same lesson as the [HTTP-transport non-bug](2026-08-03-http-transport-non-bug.md) and
the [malformed draft-guard check](2026-08-10-new-tools-and-live-verification.md#method-note--a-false-negative-in-my-own-verification):
**inspect the response shape before asserting against it** — three false negatives in nine days have
all been the client, never the server.

## Cross-references

- [../architecture/freeform-integration.md](../architecture/freeform-integration.md) — `get_form` settings sections; the UNMERGED note corrected
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md) — verbosity row now closed
- [2026-08-10-new-tools-and-live-verification.md](2026-08-10-new-tools-and-live-verification.md) — where the `list_entries` decision was raised
