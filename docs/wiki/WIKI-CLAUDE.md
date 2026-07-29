# WIKI-CLAUDE.md — craft-mcp-2rm Knowledge Wiki Schema

Claude Code session guidance for the application-level knowledge wiki at [docs/wiki/](.). Read this before creating or editing any file under `docs/wiki/`.

This wiki's primary subject is **craft-mcp-2rm** — the 2RM fork of `stimmt/craft-mcp`, an MCP server plugin that exposes a Craft CMS install (built on the 2RM content model) to AI assistants, adding Neo content-builder write/scaffold tools and a Freeform surface. This wiki tracks **decisions, architecture gotchas, and the live-QA backlog** for the fork. The user-facing product documentation (installation, tool reference) lives in `docs/*.md` and `docs/tools/` and is OUT OF SCOPE for this wiki — do not duplicate or curate it here.

## Conventions

- **Reading is not governed here.** Session orientation lives in this repo's agent-instruction file under "Project memory" (instantiated from the `/wiki` skill's `CLAUDE-MEMORY-BLOCK.md`). This file governs writes only — read it before writing, never to get oriented. Two read protocols drift; pointer-not-copy forbids it.
- **Memory is not authority.** Answer project-specific questions from this wiki, not from session recall; name the page used; if no page answers it, say so and label the inference.
- **Schema** — `schema.md` at this wiki root is the frontmatter authority (required keys, closed type vocabulary, provenance tags). Lint reads it directly; never hand-mirror its rules here.
- **Supersedence** — when a page's conclusion reverses (it asserted X, now asserts not-X, and acting on remembered X would be wrong), retire the old claim into a dated `## Superseded` block at the bottom rather than deleting it or leaving it live. Additive content, rephrasing, and always-wrong corrections are ordinary edits.
- **Pointer, not copy** — a page must not restate a fact whose canonical home is elsewhere; it links it instead.
- **Soft line budget** — curated pages target ~120 lines (a lint nudge, never a gate). `raw/` and `notes/` are exempt.
- **Provenance** — stamp `source` and `confidence` at write time (`/wiki ingest` at promotion; `/wrap` and `/dispatch` default `inferred`). `verified` only when the operator confirms in-session or the claim restates an authority surface. Three values, no more: they exist to separate verified fact from reported information from inference.

Auto-memory at `/Users/justinl/.claude/projects/-Users-justinl-repos-mbd--plugins-craft-mcp-2rm/memory/` is the **pointer layer** — `MEMORY.md` lists wiki destinations with one-sentence "why this matters" summaries. The wiki itself is the canonical store.

## Wiki operations

### Ingest

Adding new content — a locked decision, an architecture distillation, a deferred plan, a QA finding. Every ingest must:

1. Write/update the content file per the file-authority map below.
2. Update the relevant section's `index.md` with a one-line entry.
3. Append a new file to `log/YYYY-MM-DD-<slug>.md` with date, operation, scope, 1-3 sentences of context.
4. Add at least one cross-reference from the new page to a sibling, and link it from its section `index.md`. Markdown links only.
5. For external sources, file under `raw/<subdir>/` and reference from the consuming page.

### Query

Read [decisions/index.md](decisions/index.md) and the relevant section `index.md` first, drill into pages, synthesize with citations back to the specific wiki pages.

### Lint

Health-check for orphans, index gaps, dead links, stale claims, contradictions, missing cross-refs, `raw/` orphans, and decision lifecycle drift. Auto-fix mechanical issues; flag semantic ones.

## Architectural ground rules

1. **Markdown only.** Structured data lives in the codebase. The wiki references code; it does not duplicate it.
2. **`decisions/active/` is immutable for substantive changes.** Supersede rather than edit-in-place for scope/technical changes; move the old file to `superseded/`.
3. **`log/` is append-only.** One file per ingest event.
4. **One ingest = one log entry.**
5. **`raw/` is read-only.**

### Project-specific rules

- **Product docs (`docs/*.md`, `docs/tools/`) are authoritative for user-facing usage, NOT this wiki.** When a fact is about how an end user installs/configures/calls a tool, it belongs in product docs. This wiki holds the *why*, the *gotchas*, and the *not-yet-built*.
- **Live-QA findings are first-class.** Bugs found and fixed against the live mbd install get an `architecture/` gotcha page (the non-obvious root cause) or a `log/` entry — not a duplicate of the git commit.
- **Freeform / Neo write-side features discovered during QA go to `plans/qa-feature-backlog.md`** and, once committed to, a `decisions/deferred/` entry.

## File-authority map

| Path | Authoritative for |
| --- | --- |
| `decisions/active/<date>-<slug>.md` | A single locked decision: scope, rationale, relitigation trigger |
| `decisions/deferred/<date>-<slug>.md` | A planned change deliberately deferred (reason + revisit trigger) |
| `decisions/implemented/<date>-<slug>.md` | A formerly-deferred plan now executed |
| `decisions/superseded/<date>-<slug>.md` | A formerly-active decision replaced by a later one |
| `architecture/<slug>.md` | "How does X work today" — distilled architectural/integration knowledge and gotchas |
| `overview/<slug>.md` | High-level project context, goals, orientation |
| `plans/<slug>.md` | Proposed feature design / backlog before it becomes a locked decision |
| `research/<date>-<slug>.md` | Investigations, technical exploration |
| `synthesis/<date>-<slug>.md` | Cross-section analyses and reusable query results |
| `sources/YYYY-MM-DD-<slug>.md` | Ingested source summaries with provenance |
| `<section>/index.md` | Per-section catalog |
| `index.md` (top-level) | Catalog of catalogs |
| `log/<date>-<slug>.md` | One ingest event |
| `raw/**` | Preserved external source material (read-only) |

## Decision page template

```markdown
# <Title>

**Status:** active | deferred | implemented | superseded
**Date:** YYYY-MM-DD
**Scope:** <what work this decision governs>
**Supersedes:** <link, if any>
**Superseded by:** <link, when status=superseded>

## Decision
<1-3 sentence statement.>

## Rationale
<Why this over alternatives. Trade-offs accepted.>

## Cross-references
- <links>

## Do not relitigate without
<Trigger condition that would justify revisiting.>
```

## Cross-reference conventions

- Markdown links only (no `[[wikilinks]]` — they don't render on GitHub).
- Repo-relative paths from the linking file.
- Every new page links to >=1 sibling and is linked from its section `index.md`.

## When this wiki does NOT apply

| A request about… | Belongs in… |
| --- | --- |
| How to install/configure/call a tool (end-user) | `docs/*.md`, `docs/tools/` (product docs) |
| The exact code of a fix already committed | git history / the source file |
| In-flight, session-only state | auto-memory, not the wiki |
| Structured config/schema | the codebase |
