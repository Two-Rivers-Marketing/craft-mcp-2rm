---
type: log
timestamp: 2026-08-10
---

# 2026-08-10 — 12 new tools (v1.6.0), live verification, and two bugs it caught (v1.6.1)

**Operation:** log. **Scope:** filled the medium/low-impact tool gaps identified after the KCMA field report, shipped as v1.6.0, live-verified every tool on kcma.ddev.site, and fixed the two defects that verification exposed as v1.6.1.

## What shipped

**v1.6.0 — 12 new tools** (`f3bc949`). Built by four parallel subagents on non-overlapping files; `ToolRegistry` registration was held back and done centrally to avoid a three-way conflict.

| Tools | Class | Notes |
|---|---|---|
| `create_draft`, `publish_draft`, `list_drafts` | `EntryTools` | Drafts of existing entries only — creating a new entry directly as a draft needs a canonical stub, deliberately deferred |
| `get_global_set` | `GlobalSetTools` | Closes the read side of `update_global_set` |
| `get_user`, `create_user`, `update_user` | `UserTools` | No `delete_user` — cascades into content ownership |
| `render_template` | `TemplateTools` (new) | Site template mode; truncates with an explicit flag + true byte length |
| `list_queue_jobs`, `retry_failed_jobs` | `QueueTools` (new) | Guards on `QueueInterface` so a non-DB driver errors instead of fataling |
| `get_seo`, `update_seo` | `SeomaticTools` (new) | Conditional provider; flat ~17-key projection instead of the full MetaBundle |

**v1.6.1** (`ddffeee`) — the two live-verification fixes below.

Tool count went 75 → 87.

## Live verification — all 12 confirmed working

Against kcma.ddev.site over HTTP transport. Writes were exercised with a self-contained
create → mutate → delete cycle; a stray test user was removed via `tinker` and absence
re-confirmed (`entries=0 users=0`).

Highlights worth keeping:

- **`get_seo` delivers what it was built for:** 544 bytes / 17 keys against the 5,350-byte raw `seo` field on the same entry — roughly 10x smaller.
- **`update_seo` genuinely writes a per-entry override:** `get_seo`'s reported `level` flipped from `"section"` to `"entry"` after the write, with title/description/robots all reading back. That level flip is the strongest available confirmation, since it proves the override was created rather than a global being edited.
- **Derived SEO fields return Twig expressions** (`{{ entry.title }}`, `{{ seomatic.meta.seoTitle }}`) exactly as `SeomaticTools`' docblock predicts. Not a bug — SEOmatic stores the expression when a field pulls from another field.
- **`render_template` truncation is honest:** capped `html` at `maxLength` while still reporting the true `length=520`.
- **ISO-8601 confirmed on users:** `dateCreated = "2026-07-27T14:22:48-05:00"`.

## Two bugs only live testing could find (both fixed in v1.6.1)

**1. The draft-of-draft guard was unreachable dead code.** `Entry::find()` excludes drafts unless
`drafts()` is passed, so `create_draft` given a draft's element id reported
`"Entry with ID N not found"` — for an entry that plainly exists — and the guard beneath it could
never fire. Fixed with `drafts(null)`/`provisionalDrafts(null)`; the guard now produces an accurate
message naming `canonicalId` as the remedy. Verified live: the new message appears and no longer
says "not found".

**2. Response verbosity reappeared in a brand-new tool.** `list_drafts` delegated to the unfiltered
entry serializer, so one draft cost **6.6KB of which 5.4KB (81%) was a single SEOmatic MetaBundle
field**; four drafts cost 26.6KB. Field values are now opt-in via `includeFields` (default `false`) —
measured **92% reduction** live (2,316 vs 27,010 bytes) with `draftId`/`title` retained.

This is the field report's finding #2 recurring, and the lesson generalizes: **the earlier verbosity
fix was scoped to the two tools the report named (`create_entry`/`update_entry`) rather than to the
serializer every tool shares**, so each new tool that lists elements re-inherits the problem.

## Method note — a false negative in my own verification

The first draft-of-draft check passed a `draftId` where an `entryId` was expected and therefore
proved nothing, while printing as a pass. Re-running it with the draft's *element* id is what exposed
bug 1. Same failure mode as the HTTP-transport non-bug on 2026-08-03: a hand-rolled check that is
wrong in a way that looks like a result. Verify the verifier before trusting a negative.

## Open decision — `list_entries` verbosity

Measured live: **`list_entries limit=25` → 127,605 bytes, 79% the SEOmatic `seo` field**
(`limit=10` → 30KB; `limit=1` → 948 bytes, since that entry had no SEO override).

`list_drafts` was safe to fix because it was new and had no consumers. `list_entries` is
pre-existing and **mbd may depend on it returning field values**, so the default was left alone
pending an operator call:

1. **Match `list_drafts`** — fields opt-in. Biggest win, breaking for mbd.
2. **Add `includeFields` defaulting to `true`** — non-breaking, but an agent won't know to disable it.
3. **Leave it** — documented; callers keep `limit` small.

Recommendation on record: option 1 with a release note. Not actioned — it affects a live site.

## Also unresolved

`get_entry` has the same unfiltered-serializer behavior as `list_entries` (single element, so ~6KB
rather than 127KB — lower priority, same root cause).

## Cross-references

- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md) — verbosity row corrected from "done" to "partial"
- [../architecture/mcp-transport.md](../architecture/mcp-transport.md) — pagination; why `tools/list` must be walked to confirm a tool is registered
- [2026-08-03-http-transport-non-bug.md](2026-08-03-http-transport-non-bug.md) — the prior false negative this session repeated
- [2026-07-31-kcma-fixes-and-verification.md](2026-07-31-kcma-fixes-and-verification.md) — the v1.5.x work this continues
