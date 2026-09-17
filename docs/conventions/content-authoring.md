---
type: Convention
title: Content Builder Authoring
description: How to compose pages using existing content builder blocks — sequencing, feed modes, and block selection.
tags: [neo, content-builder, page-composition, authoring, entries]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# Content Builder Authoring

## Page composition pattern

Pages are built from a sequence of top-level Neo blocks in the `contentBuilder` field. Each block renders as a page section. The visual flow of the page is the block order — reordering blocks reorders the page. There is no separate layout engine; the Neo field *is* the layout.

## Text before structured content

When a structured content block (`entryFeed`, `entryListing`) needs a heading or introductory text, place a `multiColumn` block before it containing that text. Structured blocks do not carry headline fields — this is by design (see the scaffolding convention). The multiColumn + structured block pair is the standard pattern for titled listing sections.

## entryFeed vs. entryListing

| | entryFeed | entryListing |
|---|---|---|
| **Purpose** | Curated card grids | Paginated browse surfaces |
| **Count** | Limited (3–6 typical) | Full index with pagination |
| **Filtering** | Author-controlled (source + type + timeframe) | Visitor-facing filters |
| **Use for** | Homepage sections, hub teasers, related content | Full index pages (`/insights`, `/events`, etc.) |

Use `entryFeed` when the editor decides what appears. Use `entryListing` when visitors need to browse and filter a full collection.

## Feed modes

`entryFeed` supports two modes:

- **Auto** (default) — pulls entries by source + optional type filter + timeframe. Entries update automatically as new content is published.
- **Cherry pick** — editors hand-select specific entries via the `feedEntries` relation field. Use for curated collections that should not change with new publishes.

Auto is the default for most feeds. Cherry pick is for editorial curation where the exact selection matters.

## Section properties usage

Every top-level block carries `sectionProperties` (layout/spacing), `backgroundProperties` (background color/image), and `extraClasses`. These control the section wrapper, not the block's content. Use alternating backgrounds and spacing to create visual rhythm between sections. The fields live in the Section Options tab, separate from content.

## Block templates

Every top-level block type maps 1:1 to a Twig template at `templates/body_blocks/<handle>.twig`. A block type without a matching template renders nothing on the front end. Child blocks (like `entryCard`) are rendered by their parent's template — they do not have their own `body_blocks/` file.
