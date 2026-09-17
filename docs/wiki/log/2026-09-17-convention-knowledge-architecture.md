---
type: log
timestamp: 2026-09-17
---

# 2026-09-17 — convention knowledge architecture: built and shipped

**Operation:** log. **Scope:** designed and built a convention knowledge architecture for the plugin so Craft CMS building methodology travels with the MCP server to every consuming project, instead of living only in one project's memory. Fixed the KCMA violation that motivated it.

## Trigger

A KCMA dayshift agent created two Neo block types (`entryFeed`, `entryListing`) with every field — including shared property fields — dumped into a single tab, rather than split into `Content` and `Section Options` tabs. The convention existed in the KCMA team's heads but nowhere the plugin could hand to an agent building blocks in a *different* project. Content and layout fields were mixed with no structural signal separating them.

## Design decisions (arrived at via a grilling session)

- **Two knowledge sets, not one.** Scaffolding conventions (how to structure a block type) and content-authoring conventions (how to compose a page from existing blocks) are different audiences at different times — split into separate docs rather than one combined convention file.
- **Delivery: MCP Resources, not injected context.** Conventions are discoverable on-demand (`craft://conventions/*`) rather than force-fed into every session's system prompt — an agent pulls them when scaffolding, not on every unrelated call.
- **Storage: `docs/conventions/` in the plugin repo**, split into per-topic files with OKF (one-key-fact-style) frontmatter, not a single monolithic doc.
- **Dynamic resource template** `craft://conventions/{topic}` auto-registers new convention files — adding a new `docs/conventions/*.md` file does not require touching the resource-handler code.
- **`list_resources` parses OKF frontmatter** (description + tags) for skimmable discovery, so an agent can scan available conventions without fetching every file's full body first.
- **`create_block_type` auto-splits shared property fields into a Section Options tab.** New `tabLayout: auto | single` parameter — `auto` (default) separates `sectionProperties`/`backgroundProperties`/`extraClasses` from content fields into their own tab; `single` opts out for block types that genuinely don't need the split.
- **Convention scope:** "separate content from layout" applies to `topLevel` blocks and `columnItem` — not blanket-applied to every block type, since some nested/child block types don't carry the shared property fields at all.
- **Plugin `CLAUDE.md` instructs contributors to draft conventions inline in PRs** rather than requiring a separate process step — conventions get authored as part of the change that motivates them.
- **No per-project decision records for conventions.** Conventions live in the plugin, once, not re-litigated per consuming project (KCMA, mbd, etc.) — that would re-fragment the exact knowledge this architecture exists to unify.

## What was built (plugin side)

- `docs/conventions/scaffolding.md` — tab structure, field placement, content-vs-layout split
- `docs/conventions/content-authoring.md` — page composition, block sequencing, feed modes
- `src/resources/ConventionResources.php` — MCP resource handler (list + dynamic template)
- `src/enums/ResourceCategory.php` — added `CONVENTION` case
- `src/tools/NeoScaffoldTools.php` — `tabLayout` parameter on `create_block_type`
- `src/services/McpServerFactory.php` — new "Build Conventions" section in `getInstructions()`
- `CLAUDE.md` — Convention discovery section for contributors

## KCMA fixes (consuming project)

- `entryFeed` + `entryListing` Neo block-type YAMLs: removed the stray `headline` field, split fields into `Content` + `Section Options` tabs
- `entryFeed.twig` + `entryListing.twig`: removed now-dead `headline` template references

## Cross-references

- [../architecture/index.md](../architecture/index.md) — where this architecture's "how it works" distillation belongs once written
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md) — scaffolding/tab-structure gaps this closes
