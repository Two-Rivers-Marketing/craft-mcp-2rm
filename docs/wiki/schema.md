---
type: index
timestamp: 2026-07-29
description: Frontmatter schema and type vocabulary — the lint authority for this wiki.
tags: [schema, conventions]
---

# Wiki Schema

Authority for frontmatter on every curated page in this wiki. Lint reads this file — its rules are
never hand-mirrored elsewhere.

## Required frontmatter

Every new or edited curated page carries:

```yaml
type: brief | note | synthesis | strategy | framework | concept | index
timestamp: <ISO date>        # YYYY-MM-DD
description: <one line>
tags: [...]
source: "<citation>"         # required
confidence: verified | reported | inferred
```

No backfill migration of existing pages — the wiki converges through churn, not a rename pass. Tier-1
lint may mechanically backfill `source`/`confidence` (see below); nothing else is retrofit for format
alone.

## Existing header aliases

If this wiki already has a page family with its own header set (e.g. research notes using `date` /
`topics`), declare aliases here instead of renaming existing files:

- **No key aliases declared yet.** No page family here uses divergent key *names* — `date` and
  `topics` do not appear anywhere. Most existing pages carry no frontmatter at all, and none are
  retrofit.
- Two divergent local `type` values are in use and are recognized as-is, not renamed: `decision`
  (`decisions/implemented/2026-07-23-delete-form-tool.md`) and `log` (`log/*.md`). Optional extra
  keys `title` and `status` appear on that decision page and are permitted local additions.

## Closed type vocabulary

Starting set, fixed here. Adding a type means editing this file first.

| Type | Use |
|---|---|
| `brief` | Project/client briefs |
| `note` | Point-in-time captures, meeting notes, status updates |
| `synthesis` | Living research synthesis pages |
| `strategy` | Positioning, pricing, partnership structures |
| `framework` | Repeatable playbooks |
| `concept` | Design principles and architecture concepts |
| `index` | Structural/navigation pages |

Folding rules for off-vocabulary types found in the wild:

- `meeting-notes` / `meeting-summary` / `status-update` → `note`
- `research` → `synthesis` (or `note` for a point-in-time capture)
- `project-plan` → `brief`

Lint flags off-vocabulary types on edited pages only — it does not sweep untouched pages.

## Local extensions

This wiki may add local types but may not drop the required keys above or redefine a core type.

## Provenance: `source` and `confidence`

Granularity is page-level frontmatter, with an optional inline marker (e.g. `(unverified)` + a source
note) for the rare claim departing from its page's trust level. No claim-level tagging.

- `source` — required. A citation: who or what said this.
- `confidence` — one of:
  - `verified` — a human confirmed it, or it restates an authority surface for this project
  - `reported` — faithful capture of an external source; the source's own claims are unvetted
  - `inferred` — agent synthesis; no human or source directly stated this

Coverage: every curated page. Exempt: the operational layer and `raw/` staging.

Backfill (mechanical, tier-1 lint): has `source:` → `confidence: reported`; no `source:` →
`confidence: inferred`. Nothing auto-backfills to `verified`.

Writers stamp at write time (`/wiki ingest` at promotion). `verified` is minted only when a claim
matches an authority surface, or as a byproduct of a human confirming in-session — never via a
verification queue.

**Structural pages are exempt from frontmatter requirements**: `index.md`, `README.md`,
`WIKI-CLAUDE.md`, `schema.md`, `CONVENTIONS.md`, `open-questions.md`, `log.md` are operational
surfaces, not curated claims. Additionally, `log.md` is exempt from the line budget and
pointer-not-copy checks — it is an append-only changelog (same rationale as the `notes/` exemption).
