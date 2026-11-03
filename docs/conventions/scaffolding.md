---
type: Convention
title: Content Builder Scaffolding
description: Tab structure, field placement, and content-vs-layout separation rules for Neo block types.
tags: [neo, block-types, field-layout, scaffolding, tabs]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# Content Builder Scaffolding

## Content vs. layout split

Top-level blocks (`topLevel: true`) and `columnItem` blocks use two tabs: **Content** and **Section Options**. Content holds the fields that define what the block *is*. Section Options holds fields that control how the section *looks*. This separation keeps content portable — the same block renders correctly regardless of its presentation wrapper.

## Section Options tab structure

Section Options always follows the same order (CBS-25 in `content-builder-standards.md`):

1. **Properties** heading element (visual separator)
2. `sectionProperties` — Section Properties: layout and spacing
3. `backgroundProperties` — Background Properties: background color or image
4. Drafting Grid (optional, site field)
5. Section Style (optional, site field; only where a real variant exists)
6. `customCss` (when present) — Custom CSS: per-section overrides
7. `extraClasses` — Extra Classes: additional CSS classes

Drafting Grid and Section Style exist only on sites that define them; skip an absent slot, keep the rest in order. These are shared presentation fields. They never carry content meaning.

## Headings: compose by default

A section heading is composed by default: place a `multiColumn` block (column → text module) before the block it introduces. That gives a similar result every time, so most blocks, including those in the **Structured Content** group (`entryFeed`, `entryListing`, etc.), need no heading field and stay focused on their data concern.

Add a plain-text heading field only when the UI needs a specific headline or a single line of text — a section-head row (an H2 with a directional link), tab navigation, a ticker title. A heading that belongs to a block's own copy, such as a hero H1 followed by text, goes in that block's WYSIWYG, not in a separate heading field. Plain-text fields for labels that are not headings (card label overrides, disclosure-row labels, aria labels) are fine. See P-5 and CBS-07 in `content-builder-standards.md`.

## When the split does not apply

Simple child blocks (`entryCard`, `statistic`, etc.) that lack section properties stay single-tab. The two-tab split exists for blocks that render as full page sections with their own background, spacing, and layout wrapper. If a block never controls its own section chrome, adding a Section Options tab creates noise.

## Field ordering in the Content tab

Place the field that most affects the block's behavior first. Conditional or dependent fields follow the field they depend on. Example: `feedSource` before `insightType`, because `insightType` is only relevant when the source is insights. Group related fields logically — query config together, display options together.
