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

Section Options always follows the same order:

1. **Properties** heading element (visual separator)
2. `sectionProperties` — layout and spacing
3. `backgroundProperties` — background color or image
4. `customCss` (when present) — per-section CSS overrides
5. `extraClasses` — additional CSS classes

These are shared presentation fields. They never carry content meaning.

## No headline fields on structured content blocks

Blocks in the **Structured Content** group (`entryFeed`, `entryListing`, etc.) do not receive `headline` or `text` fields. When a structured block needs introductory text, place a `multiColumn` block before it in the page sequence. This keeps structured blocks focused on their data concern — querying, filtering, and displaying entries — without mixing in freeform copy.

## When the split does not apply

Simple child blocks (`entryCard`, `statistic`, etc.) that lack section properties stay single-tab. The two-tab split exists for blocks that render as full page sections with their own background, spacing, and layout wrapper. If a block never controls its own section chrome, adding a Section Options tab creates noise.

## Field ordering in the Content tab

Place the field that most affects the block's behavior first. Conditional or dependent fields follow the field they depend on. Example: `feedSource` before `insightType`, because `insightType` is only relevant when the source is insights. Group related fields logically — query config together, display options together.
