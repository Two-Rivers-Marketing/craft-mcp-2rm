---
type: Convention
title: Template Rendering Pipeline
description: The Twig rendering chain from entry to body block to column item, including the body-block-open extension pattern and section wrapper conventions.
tags: [twig, templates, body-blocks, rendering, site-toolkit, column-items]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# Template Rendering Pipeline

## The rendering chain

Entry → `_layout.twig` → contentBuilder field loop → `templates/body_blocks/{handle}.twig` per top-level block. The section template (e.g. `products/_entry.twig`) extends `_layout.twig`, which sets the path arrays, then includes `contentBuilder.twig`. That partial queries `entry[field].level(1).all()` — only top-level Neo blocks — and includes one `body_blocks/{blockType}.twig` per block. Each body block template renders exactly one page section. Child blocks (column items, tab content, etc.) are rendered by their parent's template, not by `body_blocks/` — only top-level blocks get a `body_blocks/` entry.

## body-block-open

Every body block template extends (or includes) `site-toolkit/global/_includes/body-block-open`, which owns the section wrapper: background properties (color/image/video + overlay), section properties (padding, container width, section ID), custom CSS injection, and extra classes. The block's own markup goes inside this wrapper via `{% block content %}`. Don't duplicate section-wrapper logic in individual block templates — background/spacing/class handling belongs to `body-block-open`, not to each block. Sites use a local-first override pattern: a matching template under the project's `templates/` wins over the vendor `bgd/craft-site-toolkit` version; blocks without either render nothing.

## Column item dispatch

`multiColumn` iterates its child `columnItem` blocks via `block.children`. Each `columnItem` carries its own content (text, image, card, cta, etc.) plus `columnProperties` for responsive width/breakpoint behavior (Bootstrap classes like `col-lg-6`). Dispatch resolves the column item's child type handle (`child.type.handle`) and checks whether a local template exists at `_includes/columnItems/{handle}.twig`; if so it's included, otherwise it falls through to the site-toolkit version. `multiColumn` is the only block type that takes columns, and it can recurse — a `columnItem` may itself contain a nested `multiColumn` for sub-grids.

## headerBuilder

The hero/header block. Must guard the h1 with `{% if headline %}` — image-only heroes carry no headline, and an empty h1 is an accessibility violation. The vendor site-toolkit template ships an unconditional h1; the local override exists specifically to add this guard and is load-bearing — don't remove it under the assumption the vendor template already handles it.

## Pagination

Craft's `{% paginate %}` clamps `currentPage` to `totalPages` automatically — an out-of-range page like `/p999` silently shows the last page instead of 404ing. To return a proper 404 for out-of-range pages, compare `craft.app.request.pageNum` to `totalPages` *before* the `paginate` tag and `{% exit 404 %}` if it exceeds. Separately: `|merge` on a hash with integer keys reindexes from 0, which silently corrupts paginated/indexed data structures — use `|push` or explicit string keys instead when merging into integer-keyed hashes.

## Related

- `docs/conventions/scaffolding.md` — Neo block type field/tab conventions (Content vs. Section Options split) referenced by `sectionProperties`/`backgroundProperties` above.
- `/Users/justinl/repos/mbd/docs/wiki/architecture/template-patterns.md` — full site-toolkit override map and body block inventory.
- `/Users/justinl/repos/kcma/docs/wiki/architecture/contentbuilder-agent-map.md` — content-builder mental model and block catalog from an agent-authoring perspective.
