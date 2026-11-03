---
type: Convention
title: Content Builder Standards
description: Checkable rules for any 2RM Craft site using craft-mcp, a Neo contentBuilder and site-toolkit — composition order, atomic reuse, Section Options, templates, CKEditor styles, schema workflow, content writes, tool pitfalls, and a review checklist.
tags: [neo, content-builder, standards, review, atomic-design, section-options, ckeditor, project-config, content-editing, lint]
generated: { by: agent:claude-sonnet-5-5, at: 2026-11-03T00:00:00Z }
---

# Content Builder Standards

Rules for building and changing the Neo `contentBuilder` on a 2RM site. Every rule has a **Verify** line: a command, a file that must exist, a craft-mcp call, or a review question. A rule without a Verify line is a principle and lives under Principles.

Audience: agents about to touch block types, block templates, column modules, block styles or Neo content; and a `/code-review` Standards pass walking the checklist at the end.

Paths are relative to the Craft root (the directory with `craft`, `config/`, `templates/`). On ddev sites prefix `craft` commands with `ddev`. MUST / MUST NOT are hard; SHOULD needs a stated reason to break.

## Relationship to other conventions

This page is the checkable rule set. The other conventions in this folder carry the background and are aligned with it:

| Convention | Alignment |
|---|---|
| `mcp-content-editing.md` | Neo write recipe: every write path persists nested Matrix when the payload is well-shaped. Hazards and the four-part write rule are CBS-50 to CBS-55 and [Known tool pitfalls](#known-tool-pitfalls). |
| `project-config.md` | Never hand-edit project config YAML (CBS-16). Neo order drift between DB and YAML is CBS-19. |
| `scaffolding.md` | Section Options order is CBS-25. Headings are composed by default; a heading field is the exception (P-5, CBS-07). |
| `content-authoring.md` | Text before structured content is the default heading pattern (P-5). |

Section Options order (Properties heading, `sectionProperties`, `backgroundProperties`, Drafting Grid, Section Style, `customCss`, `extraClasses`) extends `scaffolding.md`'s base order with the two optional site fields, Drafting Grid and Section Style, ahead of Custom CSS (CBS-25).

## Principles

**P-1. Preference order.** When a page needs something the builder doesn't do yet, take the first option that works:

1. Compose existing modules.
2. Add an option or field to an existing block or module.
3. Add a new column module.
4. Create a dedicated block.

**P-2. Atomic design, in Craft terms.**

| Atomic level | Craft equivalent |
|---|---|
| Atoms | `templates/_includes/` partials (link/CTA, image, heading, drafting grid, badge) |
| Molecules | Column modules (children of `columnItem`) and child blocks of a dedicated parent; shared partials such as the section head and the entry card |
| Organisms | Top-level blocks (`templates/body_blocks/<handle>.twig`) |
| Templates | Page layouts (`templates/<section>/_entry.twig`, `_layout.twig`) |

Markup is rendered through the atom partials. Nobody pastes an inline copy of CTA, image or heading markup. The `_includes/` folder is not reorganised to fit this table.

**P-3. When a block may be dedicated.** A new dedicated top-level block must pass both tests:

1. It queries data, **or** it needs per-child fields or backgrounds that a `columnItem` can't carry and no `multiColumn` arrangement can express.
2. Its children are built from existing atoms.

A recorded decision is optional. It is a way to bypass a test the block fails, not a requirement for a block that passes.

**P-4. Editors choose meaning; the system decides presentation.**

- If presentation follows from structure every single time, the template applies it automatically.
- If it is a real editor choice, it is an explicit option (a dropdown or lightswitch on the block).
- Every automatic rule is listed in the project's content-builder documentation (CBS-04).

**P-5. Headings are composed by default.**

1. **Compose the heading.** A section heading comes from a `multiColumn` → column → text module placed before the block. That always gives a similar result, so most blocks need no heading field.
2. **Use a plain-text heading field only when the UI needs one.** That means the design calls for a specific headline or a single line of text: a section-head row (an H2 with a directional link), tab navigation, or a ticker title.
3. **Use the block's own WYSIWYG for a heading that belongs to its copy.** If the heading is part of the block's copy, such as a hero H1 followed by text, it goes in that rich-text field, with no separate heading field.

Plain-text fields also stay for labels that are not headings: card label overrides, disclosure-row labels and aria labels. Heading *style* comes from CKEditor semantic styles (CBS-10), not from a field.

**P-6. One H1 per page is not enforced by tooling.** There is no schema limit on the hero block, no hero-only CKEditor config, and no lint check for H1 count. Reviewers do not flag it. Authors still guard against empty headings (CBS-36).

## Glossary

| Term | Meaning |
|---|---|
| **Content builder** | The Neo field (default handle `contentBuilder`, set by the plugin's `builderFieldHandle`) on page-like entry types. The builder *is* the page. |
| **Block type** | A Neo block type on the builder; handle is camelCase. Top-level types map 1:1 to `templates/body_blocks/<handle>.twig`. Toolkit docs call these "body blocks"; wikis often say "component". |
| **Block type group** | A Neo CP grouping (Content, Structured Content, Forms, Functionality…). The CP groups by **order position**, not by the `group:` key (CBS-20). Cosmetic: groups never reach markup. |
| **Top-level block** | `topLevel: true`. One page section. Rendered by `body_blocks/<handle>.twig`. |
| **Child block** | `topLevel: false`. Valid only inside a parent that lists it in `childBlocks`. Rendered by the parent's template or, for `columnItem` children, by `_includes/columnItems/<handle>.twig`. |
| **Neo order** | `config/project/neo/orders/<fieldUid>.yaml`: one flat list holding block types **and** groups. A group contains the types that follow it. The DB `sortOrder` must agree (CBS-19). |
| **Shared property fields** | Fields that carry presentation only: `sectionProperties`, `backgroundProperties`, `extraClasses`, `customCss` (craft-mcp's scaffold set); sites may add `draftingGrid`, `sectionStyle`, `columnProperties`. Never content. |
| **Section Options** | The second tab on a top-level block holding the shared property fields, in a fixed order (CBS-25). |
| **Section Style** | An optional dropdown inside Section Options for a real visual variant that cannot be inferred from content. Present only where a variant exists. |
| **Column item** | The Neo `columnItem` block: one column inside `multiColumn`. Carries column properties and background; holds modules. Not the same as the Matrix entry type of the same handle some sites use for tab columns. |
| **Column module** | A child of `columnItem` (text, image, cta, card, …). Template: `_includes/columnItems/<handle>.twig`. Toolkit READMEs also call these "column items"; this page says *column module*. |
| **Section head** | The shared partial for the "H2 with directional link" row: a plain-text headline, one optional directional link, and a rule underneath (CBS-08). |
| **Atom / molecule / organism** | See P-2. |
| **body-block-open** | Toolkit wrapper `site-toolkit/global/_includes/body-block-open`. Emits the `<section>` and applies section properties, background, custom CSS and extra classes. Templates fill `{% block extraClass %}` and `{% block content %}`. |
| **Local override** | A file under `templates/` shadowing the toolkit file of the same path. Lookup is local-first. |
| **CKEditor semantic style** | An entry in a CKEditor config's `style.definitions` that tags text with a class describing *what it is* (Section Heading, Lede, Eyebrow), not how it looks. |
| **Automatic rule** | Presentation the template applies from content structure with no editor input (P-4). Each is listed in the project documentation. |
| **Dedicated block** | A top-level block that exists for one layout or data source, as opposed to the generic `multiColumn` + modules. Governed by P-3. |

## Rules

Format: **Rule** → **Why** → **Verify**.

### A. Composition and modelling

**CBS-01 — A change MUST follow the P-1 order. A new block type or module needs a stated reason why options 1–3 failed.**
*Why:* every new block type adds a template, a field layout, a style and a review surface forever.
*Verify:* any diff adding `config/project/neo/blockTypes/*.yaml` or editing a `columnItem` `childBlocks` list carries, in the PR or commit body, the P-1 options tried and why each failed. Review question: "could this be a `multiColumn` recipe or an option on an existing block?"

**CBS-02 — A new dedicated top-level block MUST pass both P-3 tests, or carry a decision record that bypasses the failing one.**
*Why:* keeps layout-fixing blocks out; data-driven blocks and per-child-background blocks are the two legitimate cases.
*Verify:* new `topLevel: true` YAML → the template either queries (`craft.entries()`, `craft.assets()`, a relation read) or the decision record names the per-child field/background a `columnItem` cannot carry. The template's children render through existing atoms: `grep -nE '<svg|target="_blank"|<img ' templates/body_blocks/<handle>.twig` returns nothing that an atom already owns.

**CBS-03 — SHOULD extend an existing dedicated block with an option rather than add a sibling block for a layout variant.**
*Why:* sibling blocks fork templates, fields and styles.
*Verify:* new block whose child types equal an existing block's child types → reject unless the diff explains why an option can't do it.

**CBS-04 — Presentation derived from content structure MUST hold every time. Every such rule MUST be listed in the project's content-builder documentation; a rule that fails "every time" MUST be an explicit option instead.**
*Why:* inference that is right 90% of the time produces silent wrong layouts and false negatives the editor can't fix (P-4).
*Verify:*
```bash
grep -rnE "in childTypes|\.children\.all\(\)|childCounts|uniqueTypes" templates/body_blocks templates/_includes
```
Every hit that feeds a class name or variant appears in the project's "automatic presentation rules" list. New inference with no list entry → reject.

**CBS-05 — A visual variant an editor picks MUST be a dropdown option (Section Style or a block field), never an `extraClasses` value. Importers and scripts MUST NOT write `extraClasses`. An `extraClasses` value MUST NOT duplicate a class the template already adds automatically.**
*Why:* clients never type CSS class names; EC cannot remove or override template classes, so a duplicate does nothing and a conflicting value fights the auto class.
*Verify:* `grep -rnE "'?extraClasses'? *=>" scripts/ *.php` returns nothing; new SCSS comment of the form `(extraClasses: …)` → flag; option added to the field YAML instead.

**CBS-06 — CTA, link, image and heading markup MUST be rendered through the shared atom partials, included `with { … } only`, optional params defaulted with `??`. Do not paste an inline copy.**
*Why:* every inline copy drifts (arrow icons, alt chains, new-tab handling all diverged this way).
*Verify:*
```bash
grep -rnE 'target="_blank"|<svg' templates/body_blocks templates/_includes/columnItems
```
Hits outside the link/CTA/icon atoms → flag. An atom that now appears inline in two templates becomes a partial before a third copy is added.

**CBS-07 — A new heading field MUST NOT be added unless the UI needs a specific headline or a single line of text. A heading that belongs to a block's own copy MUST go in that block's WYSIWYG.**
*Why:* P-5. A composed heading (`multiColumn` → column → text module) covers most cases. A heading field is justified only when the design needs a fixed piece of UI that composition can't produce. Examples are the section-head row, tab navigation and a ticker title.
*Verify:* for every new `craft\fields\PlainText` field in the diff that a template renders inside `<h1>`–`<h6>`, the project documentation must list it with the UI it drives. If not, reject it, and ask whether a composed text module would do. For a block with a WYSIWYG, its template must not render a separate plain-text field as the heading of that WYSIWYG's copy. Allowed whatever the context: card label overrides, disclosure-row labels and aria labels.

**CBS-08 — A block that renders the "H2 with directional link" row MUST render it through the section-head partial, not with inline markup.**
*Why:* one markup path for that piece of UI. The same look is available in composed text through the CKEditor "Section Heading" style.
*Verify:* `grep -rn '<h2' templates/body_blocks` returns no inline H2-plus-link row that the section-head partial could render.

**CBS-09 — Eyebrow text MUST be a CKEditor semantic style ("Eyebrow"), not a field.**
*Why:* an eyebrow is a text role inside rich text; a field per block multiplies label handling.
*Verify:* the CKEditor config `style.definitions` contains `name: Eyebrow` with a `<p>` element and the site's label class (the project documentation names the class); no new field is labelled "Eyebrow" (`grep -rn "label: Eyebrow" config/project/neo`).

**CBS-10 — CKEditor styles MUST describe meaning only. The allowed set is Section Heading, Lede and Eyebrow, each mapped to a class the project documentation names (for example `h2.sec-head`, `p.t-lede`, `p.t-label`); nothing else. Buttons come from the CTA atom, never from rich-text styles. The config's editor CSS MUST NOT hard-code colors. The set is locked: `htmlSupport` allows only the allowed classes, the Source button is admin-only, and there is no HTML Embed.**
*Why:* appearance belongs to the theme; styles that carry button chrome or hex colors bypass the design system and the CMS-controlled palette. A style set is only enforced if editors can't paste around it with free-form HTML, inline styles or an embed.
*Verify:*
```bash
grep -n "^        name:" config/project/ckeditor/configs/*.yaml            # names = Section Heading, Lede, Eyebrow
grep -nE '#[0-9a-fA-F]{3,8}\b|rgba?\(|hsl' config/project/ckeditor/configs/*.yaml   # prints nothing
grep -n -A8 "htmlSupport:" config/project/ckeditor/configs/*.yaml          # no `classes: true`, `attributes: true` or `styles: true`; classes list = the three allowed classes from the project documentation
grep -n "htmlEmbed" config/project/ckeditor/configs/*.yaml                 # prints nothing
```
`sourceEditing` is not in the toolbar for non-admins (the toolbar, or a per-user-group toolbar override, shows it to admins only). Before locking an existing site, count rich-text values with `<iframe`, `<oembed`, `style=` and the classes of any style being removed (SELECT over `elements_sites.content`); record the counts in the PR and move or fix each hit.

**CBS-11 — New work MUST NOT hide builder content from the template (CSS `display:none` on a builder section, or a template filter that drops a block) and MUST NOT style a section by its Section ID. A content-text trigger that changes presentation is allowed only if the project documentation records it as a brand rule; no new text triggers.**
*Why:* content placement is block order; an ID or text match breaks silently when an editor renames a section or retypes a heading.
*Verify:*
```bash
git diff -U0 -- '*.scss' | grep -E '^\+.*(^|[ ,{])#[A-Za-z]'                # new #id selectors (hex colors match too; skim)
git diff -U0 -- '*.scss' | grep -E '^\+.*display: *none'                    # review each for builder sections
git diff -U0 -- 'templates/**/*.twig' | grep -E "^\+.*\|lower (starts with|==)"   # new text triggers
```

**CBS-12 — Shared property fields carry presentation only. A content field's value MUST NOT select a layout or style, and a presentation field MUST NOT decide *what* content renders.**
*Why:* keeps content portable between presentation wrappers (`scaffolding.md`).
*Verify:* no template reads `sectionProperties` / `backgroundProperties` / `extraClasses` to choose which content to output; review question for any `{% if block.<contentField> == … %}` that sets a class.

**CBS-13 — Reuse existing global fields (`headline`, `richText`, `plainText`, `label`, `cta`, `images`, `entry`, …) with per-layout label overrides. A new field needs a reason in the commit.**
*Why:* field instances keep the count and the migration surface low; `create_block_type` already matches existing fields before creating.
*Verify:* new `config/project/fields/*.yaml` in the diff → commit message justifies it; no near-duplicate handle.

**CBS-14 — Field `instructions` MUST describe editor-visible behaviour and be updated in the same diff as the behaviour.**
*Why:* stale instructions mislead editors and agents (for example, a drafting-grid option documented as working while it did nothing).
*Verify:* behaviour change → matching `instructions:` change in the same diff.

**CBS-15 — Every editor-visible field and option MUST be read by the template it belongs to.**
*Why:* a field that does nothing teaches editors the CP lies.
*Verify:* for each field handle in a block type's YAML, `grep -n '<handle>' templates/body_blocks/<block>.twig` (or its module template) has a hit; a partial that accepts a param (e.g. `draftingGrid:`) uses it.

### B. Schema workflow and tooling

**CBS-16 — Nobody hand-edits files under `config/project/`.** Schema changes go through the Craft API, the Neo API or craft-mcp. YAML is Craft's output.
*Why:* hand edits drift from what Craft would write and fail on apply.
*Verify (the review gate):* on a fresh checkout of the branch, `craft project-config/apply` changes nothing and `craft project-config/diff` prints "No pending project config YAML changes." Then `craft project-config/write && git diff --exit-code config/project` exits 0 (run it on a throwaway checkout). Commit message names the tool used.

**CBS-17 — Create block types with craft-mcp `create_block_type` or Neo's `blockTypes->save`, never YAML-first. After `create_block_type`, fix its known output before committing:**
(a) the stub's `extends "global/_includes/body-block-open"` becomes `extends "site-toolkit/global/_includes/body-block-open"` unless the project has a local copy; (b) `{{ containerClass }}` becomes `{{ containerClass ?: 'container' }}`; (c) `draftingGrid`, if used, moves from the Content tab to Section Options (CBS-25); (d) child-only types are created with `scaffoldTemplate: false`; (e) group and order are set (CBS-19, CBS-20).
*Why:* YAML-first fails with `Undefined array key "sortOrder"`; the stub and tool defaults are wrong on those five points (craft-mcp v1.8.0).
*Verify:* new template's first line extends the `site-toolkit/` path; `grep -n "{{ containerClass }}" templates/body_blocks/*.twig` is empty; tab check per CBS-24.

**CBS-18 — Raw PHP schema scripts (`ddev exec php …`, a bootstrapped Craft) are allowed only when craft-mcp is down. Such a script MUST end with `project-config/write`, leave `project-config/diff` clean, and MUST NOT be committed.**
*Why:* a bootstrapped script skips the end-of-request flush, so DB saves never reach the YAML while `project-config/diff` reports clean for the wrong reason.
*Verify:*
```bash
git ls-files | grep -E '\.tmp\.(php|mjs)$'          # prints nothing
git diff --name-only | grep -E '\.tmp\.(php|mjs)$'  # prints nothing
craft project-config/diff                           # "No pending project config YAML changes."
```
The script ends with `Craft::$app->getProjectConfig()->saveModifiedConfigData();` before `project-config/write` (`project-config.md`). If `write` misses a field, `project-config/rebuild`.

**CBS-19 — After any Neo schema change the DB `sortOrder` of every block type and group MUST equal its position in the order YAML, and the order YAML MUST list every block type and group exactly once.**
*Why:* in-process Neo edits update the stored config but skip the DB rewrite (`handleChangedOrders` only runs on external apply), and `project-config/diff` stays clean. The next Neo-API save of a type then writes its order slot from a stale `sortOrder` and duplicates or drops entries, which fails deploys.
*Verify (from the Craft root):*
```bash
n=$(ls config/project/neo/blockTypes | wc -l); g=$(ls config/project/neo/blockTypeGroups | wc -l)
o=$(grep -c '^- ' config/project/neo/orders/*.yaml); echo "types=$n groups=$g orders=$o"   # orders = types + groups
grep -o "'block[A-Za-z]*:[0-9a-f-]*'" config/project/neo/orders/*.yaml | sort | uniq -d      # prints nothing
```
DB side (`run_query` is SELECT-only; or `mysql`): compare to the 1-based line numbers of `grep -n '^- ' config/project/neo/orders/*.yaml`:
```sql
SELECT bt.handle, IFNULL(g.name,'NULL') grp, bt.sortOrder FROM neoblocktypes bt
LEFT JOIN neoblocktypegroups g ON g.id = bt.groupId ORDER BY bt.sortOrder;
SELECT name, sortOrder FROM neoblocktypegroups ORDER BY sortOrder;
```
Mismatch → re-sync before any other schema save (try `project-config/apply --force` first; it re-syncs `sortOrder` from the YAML. Verify the positions match, and if the DB order doesn't move, use Neo's API).

**CBS-20 — Block type groups are decided by position in the Neo order. Every block type MUST have a non-null `group:` that matches the group it sits under in the order file. No group is empty.**
*Why:* the CP puts each type under the last group before it in the sorted list; `group:` is only a cache that a CP field save re-derives from position. A null `group:` is invisible until that save cements the position's group.
*Verify:*
```bash
grep -L "^group: [0-9a-f]" config/project/neo/blockTypes/*.yaml   # prints nothing
```
Walk `config/project/neo/orders/*.yaml`: for each `blockType:` line the nearest preceding `blockTypeGroup:` line is the type's `group:` UID, and every group is followed by at least one type.

**CBS-21 — After deleting a field, every layout `fieldUid` MUST resolve to a field file.**
*Why:* a dangling UID breaks apply.
*Verify (from `config/project`):*
```bash
grep -rhoE "fieldUid: [0-9a-f-]{36}" neo entryTypes | awk '{print $2}' | sort -u | while read u; do ls fields/*--$u.yaml >/dev/null 2>&1 || echo "dangling $u"; done
```

**CBS-22 — A lint pass MUST run on every builder schema change. The craft-mcp tool `lint_content_builder` (planned; not shipped yet) checks exactly four things:**
1. every block type's template exists (per CBS-30);
2. every block type has a group (CBS-20);
3. the order is complete and matches the DB (CBS-19);
4. each top-level block's Section Options set and order are correct (CBS-24, CBS-25).

Until the tool exists, the reviewer runs the checks in CBS-19, CBS-20, CBS-24/25 and CBS-30 by hand against this page.
*Verify:* `list_mcp_tools` lists `lint_content_builder` → call it; zero findings. If absent, the PR description shows the four manual checks passed.

**CBS-23 — A schema change (block type, field, option, nesting, tab) MUST update the project's content-builder documentation in place (supersede; don't append contradicting text). That page holds the block inventory, the automatic presentation rules, the Section Style options and the exceptions on record.**
*Why:* the project page is the authority this standard points at (CBS-04, CBS-02); a stale inventory is worse than none.
*Verify:* schema diff with no change to the project page in the same session → flag.

### C. Field layouts and Section Options

**CBS-24 — A top-level block type MUST have exactly two tabs, named `Content` then `Section Options`.**
*Why:* separates what the block *is* from how it looks (`scaffolding.md`). Variants ("Section Properties", "Properties", a tab named after the block) break tooling and muscle memory.
*Verify:* Neo YAML lists a tab's elements and then its `name:` line, so tab names are the 8-space `name:` lines:
```bash
for f in $(grep -l '^topLevel: true' config/project/neo/blockTypes/*.yaml); do
  echo "$(basename ${f%%--*}): $(grep -E "^        name: " $f | sed -E 's/^ +name: //' | tr '\n' '|')"; done
```
Each line is `Content|'Section Options'|`.

**CBS-25 — Every top-level block's Section Options tab MUST carry the same set, in this order: Properties heading, Section Properties, Background Properties, Drafting Grid, Section Style, Custom CSS, Extra Classes. Section Style appears only where a real variant exists.** Where a site has no Drafting Grid field, skip that slot. The order matches `scaffolding.md` (`customCss` before `extraClasses`); Drafting Grid and Section Style are the site-level additions.
*Why:* one layout for every block; editors and agents never hunt.
*Verify:* the field comments in each YAML appear in that order before the `'Section Options'` `name:` line:
```bash
for f in $(grep -l '^topLevel: true' config/project/neo/blockTypes/*.yaml); do
  echo "$(basename ${f%%--*}): $(grep -E "^        name: |# (Section Properties|Background Properties|Drafting Grid|Section Style|Custom CSS|Extra Classes)$" $f | sed -E 's/^ +name: //; s/.*# //' | tr '\n' '|')"; done
```
The segment before `'Section Options'|` reads `Section Properties|Background Properties|Drafting Grid|[Section Style|]Custom CSS|Extra Classes|`. A block with a different set or order is listed in the project documentation with the decision that allows it, or fails.

**CBS-26 — The Drafting Grid field MUST offer only Off and Lines, on every block (one shared field). The cursor reveal is a template rule, not an option: the template applies it to the hero and the footer automatically, and no field value turns it on anywhere else.**
*Why:* P-4. The reveal follows from where the band sits (hero and footer) every time, so it is automatic; a per-block "reveal" value either does nothing (non-hero) or duplicates the automatic rule. A Craft dropdown's options are field-wide, so the choice cannot be limited to one block, and removing it from the field is the only clean fix.
*Verify:* the Drafting Grid field YAML lists exactly two options (`none`, `lines`); no stored value `reveal` (SELECT count over `elements_sites.content`, 0); the reveal appears in the project's automatic-rules list (CBS-04); field instructions match (CBS-14). Sites without Drafting Grid skip this rule.

**CBS-27 — Extra Classes and Custom CSS are developer-only. Extra Classes MUST be hidden from non-admin editors by a field-layout `userCondition`. Custom CSS is never used for shipped styling. Review flags any new non-empty value of either in content.**
*Why:* both bypass the design system; a developer escape hatch is not an editor feature.
*Verify:* every `# Extra Classes` layout element has a non-null condition:
```bash
grep -A12 '# Extra Classes' config/project/neo/blockTypes/*.yaml | grep "userCondition: null"   # prints nothing
```
Content check: count non-empty `extraClasses` / `customCss` per block type with `tinker` (`Block::find()->type($handle)`, read the field) or `run_query` over `elements_sites.content` keyed by the layout-element UID; compare to the project's recorded baseline. Any increase → flag.

**CBS-28 — Child-only modules MUST use one `Content` tab. Exceptions: `columnItem` (a `Column Properties` tab) and cards of a dedicated parent that own a background (a `Background` tab).**
*Why:* modules never control their own section chrome (`scaffolding.md`).
*Verify:* child YAML has one tab unless it is `columnItem` or holds `backgroundProperties`.

### D. Templates

**CBS-29 — Every top-level block template MUST `{% extends "site-toolkit/global/_includes/body-block-open" %}`, put markup in `{% block content %}` and root classes in `{% block extraClass %}`. A block that extends it MUST carry `sectionProperties`. A block with a hand-rolled `<section>` (for example a header builder) is a documented exception in the project documentation and MUST still carry and render Extra Classes.**
*Why:* background, spacing, container, custom CSS and extra classes live in one wrapper; hand-rolled sections drop some of them silently.
*Verify:*
```bash
grep -L 'extends "site-toolkit/global/_includes/body-block-open"' templates/body_blocks/*.twig   # only documented exceptions
```
A hand-rolled `<section>` in any other block → reject. An exception's YAML has `# Extra Classes` and its template outputs `block.extraClasses`.

**CBS-30 — Every top-level block type MUST have a local `templates/body_blocks/<handle>.twig`. Every `columnItem` module MUST have `templates/_includes/columnItems/<handle>.twig`; a new module's template MUST be local. Children rendered inline by a dedicated parent need no template; the parent's header comment lists them.**
*Why:* 1:1 block-to-template rule; toolkit fallbacks are not styled by the site theme (`templates.md`, `content-authoring.md`).
*Verify (from the Craft root):*
```bash
for f in config/project/neo/blockTypes/*.yaml; do h=$(basename "${f%%--*}"); tl=$(awk '/^topLevel:/{print $2}' "$f")
  [ "$tl" = true ] && p=body_blocks/$h.twig || p=_includes/columnItems/$h.twig
  [ -f templates/$p ] && s=local || { [ -f vendor/bgd/craft-site-toolkit/templates/$p ] && s=toolkit || s=MISSING; }
  echo "$h $tl $s"; done | column -t | grep -v ' local$'
```
Every MISSING is a child of a dedicated parent that the parent's header comment names (or a disabled type); every `toolkit` row for a *new* type → reject. Existing toolkit fallbacks are listed in the project documentation as backlog. `describe_content_builder` reports `exists:false` for inline children today; that is a tool gap, not a finding (see pitfalls).

**CBS-31 — The inner wrapper MUST be `<div class="main-content {{ containerClass ?: 'container' }}">` (or `{{ containerClass ?: 'container' }} <block>__inner`). A template MUST NOT hard-code a width class.**
*Why:* Container Width is the only source of section width (CBS-43).
*Verify:* `grep -n 'class="[^"]*\bcontainer\b' templates/body_blocks/*.twig` hits only `?: 'container'` fallbacks.

**CBS-32 — Where a block carries a Drafting Grid field, its template MUST render the site's grid partial first inside `content`, passing `block`.**
*Why:* a partial called with `draftingGrid:` instead of `block:` renders nothing.
*Verify:* every `topLevel` YAML with `# Drafting Grid` has a template containing the grid include with `block: block`. (Conditional on the site having the field.)

**CBS-33 — Every block and module template MUST open with a header comment: purpose, Neo fields, children, variants, and an ACCESSIBILITY section when it renders media, motion or interaction.**
*Why:* agents read the template first; the comment is the cheapest spec.
*Verify:* the first lines of a new template are `{# … #}` naming its fields.

**CBS-34 — Empty content MUST render nothing. Rich text is tested with `|striptags|trim|length`, plain text with `|length`, relations with `.one()` / `.all()|length`; dropdowns are read as `.value ?? ''` with an explicit default.**
*Why:* empty tags are accessibility violations; a bare dropdown prints the field object.
*Verify:* new `{{ block.<field> }}` output without a guard → flag; a dropdown printed without `.value` → flag.

**CBS-35 — In column module templates the module is `child`. A module MUST NOT read its own data from `block` or `entry` (those are the `multiColumn` and the `columnItem`).**
*Why:* toolkit `columnItem.twig` scopes `child`, `item`/`entry` = the column, `block` = the parent.
*Verify:* `grep -nE '\b(block|entry)\.' templates/_includes/columnItems/*.twig` → only intentional parent reads.

**CBS-36 — Headings MUST be guarded so an empty field emits no heading tag.**
*Why:* an empty `<h1>`–`<h6>` is an accessibility violation (`templates.md`). Heading *count* is not enforced (P-6).
*Verify:* each `<h[1-6]` in a block or module template sits inside an `{% if x|striptags|trim|length %}` (or equivalent) guard.

**CBS-37 — Data blocks MUST eager-load the relations they render and scope queries explicitly (section, type, order, limit).**
*Why:* N+1 queries on listing pages.
*Verify:* `grep -n 'craft.entries()' templates/body_blocks/*.twig` — each has `.with([...])` for rendered relations and an explicit scope.

**CBS-38 — No dead templates or handles: every `body_blocks/*.twig` basename maps to a `topLevel: true` block type; every `_includes/columnItems/*.twig` to a child type; templates MUST NOT test for block-type handles that do not exist.**
*Why:* dead files mislead agents about what renders.
*Verify:*
```bash
for f in templates/body_blocks/*.twig; do b=$(basename $f .twig); ls config/project/neo/blockTypes/$b--*.yaml >/dev/null 2>&1 || echo "no type: $b"; done
grep -rnoE "in \[('[a-zA-Z]+',? ?)+\]" templates     # every block-type handle listed here must exist under config/project/neo/blockTypes
```

**CBS-39 — A new block's root class MUST be its handle in kebab-case (`entryFeed` → `entry-feed`), BEM below it (`__element`, `--modifier`). Existing short roots keep their names until that block is refactored.**
*Why:* a predictable rule beats per-block naming; renaming live roots is churn.
*Verify:* `{% block extraClass %}` of a new template. The project documentation lists grandfathered roots.

**CBS-40 — Alt text MUST resolve in this order: (1) the per-use `altText`, (2) the asset's `alt`, (3) empty, meaning the image is decorative. It MUST NOT fall back to the asset title.**
*Why:* titles are filename-derived and read aloud as noise.
*Verify:* `grep -rnE 'alt[^=]*=[^>]*\.title|altText[^}]*\.title' templates` prints nothing.

### E. CSS and JS

**CBS-41 — Colors come only from `backgroundProperties` → the CMS palette class. Block styles read theme variables and MUST NOT hard-code ground colors.** A template default (a hero's default ground) is allowed only when the header comment and a decision record it.
*Why:* the palette is CMS-authoritative; a hard-coded hex breaks when the client changes it.
*Verify:* new hex values in the block stylesheet outside variable fallbacks → flag.

**CBS-42 — Block styles live in the site's block stylesheet (named in the project documentation) or its shared-atoms stylesheet. A template `<style>` is allowed only for selectors that need the block id (tab radios, per-block background images).**
*Why:* generic inline rules escape review and caching.
*Verify:* new `<style>` in a template without `{{ block.id }}` in its selectors → flag.

**CBS-43 — Width comes only from Container Width. A block's container width MUST NOT be set by block class, extra class or ID in SCSS.** `container-fluid` is stock Bootstrap: edge to edge, Bootstrap padding, **off** the site's gutter. It is the one option whose content does not line up with the nav or grid; document it where used.
*Why:* one width source; `container-fluid` is easy to mistake for the gutter-aware extra-large option.
*Verify:* `max-width` on `.main-content` / `.container` under a block selector → reject; the project documentation carries the `container-fluid` note.

**CBS-44 — JS hooks use `data-*` attributes, and content MUST be visible without JS (gate collapsed states on a `.js` class on `<html>`).**
*Why:* a failed script must not hide content.
*Verify:* new JS that selects by a styling class → flag; CSS that hides content without a `.js` ancestor → flag.

### F. Accessibility

**CBS-45 — Decorative media uses `alt=""`. Background video carries `muted loop playsinline preload="none" aria-hidden="true" tabindex="-1" data-bg-video` and is started by JS only without reduced motion.**
*Why:* background video is decoration, not content.
*Verify:* any new `<video>` missing one of those attributes → reject.

**CBS-46 — External links open in a new tab with `rel="noopener"` and a visually hidden "(opens in a new tab)". In-page and site-relative links never do. Use the CTA/link atom.**
*Why:* one implementation of the new-tab pattern.
*Verify:* `grep -rn 'target="_blank"' templates` hits only the atom.

**CBS-47 — Interactive widgets use a real `<button>` with `aria-expanded` / `aria-controls`, labelled regions, or native `<details>` / `<summary>`. Carousels and tickers pause on hover and focus and stand still under reduced motion.**
*Why:* keyboard and motion-sensitivity access.
*Verify:* clickable `div` / `span` → reject; new motion without a `prefers-reduced-motion` rule → reject.

**CBS-48 — IDs MUST NOT be empty or duplicated. Guard `id` on `sectionId`; build ids from the element's own id. An `<iframe>` MUST have a specific `title`.**
*Why:* `id=""` and repeated ids break anchors and assistive tech.
*Verify:* `grep -rnE 'id="\{\{' templates` — each has a guard and a unique key.

### G. Content writes

**CBS-49 — Call `describe_content_builder` (or `get_block_type`) before any Neo write.**
*Why:* writing against a guessed schema is the usual cause of silent drops (wrong entry-type handle, missing sub-field).
*Verify:* process rule. The PR or session log shows the describe call for each touched block type.

**CBS-50 — Writing Neo content: any of the four write methods may be used (`create_neo_block`, `update_neo_block`, a one-save `setFieldValue` + `saveElement` of the whole tree, or per-block saves), but every write follows CBS-51 to CBS-54.**
*Why:* on craft-mcp v1.8.0 / Neo 5.5.13 all paths persist nested Matrix when the payload is valid; the risk is payload shape and complete-set semantics, not the path.
*Verify:* see the next four rules.

**CBS-51 — (a) Matrix shape. A nested Matrix value MUST be a JSON list of `{"type": <entryTypeHandle>, "fields": {…}}` entries, each with `sortOrder` (or `{"entries": {…}, "sortOrder": […]}`). Neo block values also carry `sortOrder` (listing existing block ids plus `newN`). Never a bare object, never sub-field keys outside `fields`. Entry-type handles come from `describe_content_builder` / `get_block_type`.**
*Why:* Craft and Neo ignore what they can't parse and the save still reports success (see the five payload shapes under pitfalls).
*Verify:* reject any payload with a Matrix value that is not a list or `entries` + `sortOrder`; a key beside `type` / `fields`; `blocks` without `sortOrder`; `children` under a block type whose `childBlocks` is null.

**CBS-52 — (b) Updates are complete sets. `update_neo_block` replaces values in full: send the complete Table row and every Matrix entry that should be kept (keyed by existing id). A list value or an omitted id deletes entries.**
*Why:* a partial row leaves unsent cells `null` (lost padding and container classes); a keyed map deletes entries it doesn't list.
*Verify:* read the block first (script or `tinker`) and diff the payload against it; any Table row with fewer cells than the field defines → reject.

**CBS-53 — (c) New blocks. Leave a Table field out so it gets its defaults, or send the full row. Never send a partial row. For `sectionProperties` the full row is `col8 container`, `col1 align-items-start`, `col2 justify-content-start`, `col4 section-padding-top-default`, `col5 section-padding-bottom-default`, `col9 1` (`col3`, `col6`, `col7` optional).**
*Why:* tables are stored verbatim; nulls are never merged with defaults. In the CP a null select displays as the first option and a later CP save persists it.
*Verify:* any scripted or MCP payload with a partial `sectionProperties` row → reject. (API writes use `colN` keys; Twig reads handles such as `row.containerWidth`.)

**CBS-54 — (d) Read back. After every write, re-read the touched blocks with a script or `tinker`. `get_entry` returns block ids only (and only the first 10).**
*Why:* only a read-back catches silent drops.
*Verify:* for each touched block and each Matrix field, entry count and values equal the payload; structure rows equal block count with no duplicate ids. Record the counts in the PR or log.

**CBS-55 — Batches stay under 50 saves per `tinker` call. After a timeout, read before retrying.**
*Why:* the 120s MCP timeout does not kill the PHP; a retry duplicates the write. Every entry save also writes a full revision of the tree (about 26 new elements for a 12-block page), so fewer, larger saves are cheaper.
*Verify:* each loop in a `tinker` call is bounded < 50; make batches idempotent (check current state first).

## Known tool pitfalls

Observed on craft-mcp v1.8.0, Neo 5.5.13, Craft 5.11.2, 2026-11-03.

1. **`update_neo_block` is full replacement.** A Table value is the whole row (sending `[{col4: …}]` nulls the other eight cells). A Matrix **list** replaces every entry (new ids, old deleted). An **id-keyed map** updates in place and keeps unsent sub-fields, but **deletes any existing entry left out of the map**. Read first, send everything.
2. **Five payload shapes succeed (`success: true`) but lose data** ([#39](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/issues/39)):

   | Payload | Result |
   |---|---|
   | `{entries:{new1:…}}` with no `sortOrder` | 0 entries written |
   | Bare `{type, fields}` object, not in a list | 0 entries written (script and `create_neo_block`) |
   | `[{type, color:[105]}]`, sub-fields not under `fields` | entry created **empty** |
   | `[{type:"bgColor", …}]`, wrong entry-type handle | entry dropped |
   | Neo value `{blocks:{new1:…}}` with no `sortOrder` | new block dropped; existing kept |

   Valid shapes: a list of `{type, fields}`; `{new1: {type, fields}}`; `{entries:{…}, sortOrder:[…]}`; the Craft 4 `{blocks:{…}, sortOrder:[…]}`. Omitting `sectionProperties` on a new block stores the full default row.
3. **`get_entry` cannot verify nested data.** It returns block ids only, first 10 of N. Verify with a script or `tinker` until a Neo tree read tool exists ([#40](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/issues/40)).
4. **`create_block_type` defaults.** `sortOrder = count(blockTypes) + 1`, ignoring groups, so it can land on an existing group's slot and overwrite it in the flat order list. It never sets a group. The stub extends a non-toolkit path and prints a bare `{{ containerClass }}`. `draftingGrid` is not in its shared-field set and lands on the Content tab. Child-only types need `scaffoldTemplate: false`. YAML-first creation fails with `Undefined array key "sortOrder"`.
5. **Console and bootstrap scripts don't flush project config.** `Craft::$app->getProjectConfig()->saveModifiedConfigData()` then `project-config/write`; confirm with `project-config/diff`. Don't run `write` after someone else's `apply` before reading their YAML (it overwrites it).
6. **Neo order drifts between DB and YAML after in-process edits.** Editing the order list through `tinker` or the Neo API updates stored config but not `neoblocktypes.sortOrder`. `project-config/diff` stays clean; the CP menu groups by DB position. A later Neo-API save of a type writes its slot from the stale `sortOrder`, which duplicates or drops order entries and fails the next deploy. Check with CBS-19 after every in-process schema edit.
7. **A block type with `childBlocks: null` takes no Neo children.** The tool rejects `children` and `parentBlockId` cleanly. A raw script would not validate, so a script must not try. Such a block's body, when it has one, lives in a Matrix field.
8. **`describe_content_builder` reports `exists:false`** for children a dedicated parent renders inline (it checks `_includes/columnItems/<handle>.twig` for every child). A tool gap: `describe_content_builder` should become parent-aware. Not a finding until then.
9. **Hard-deleting an entry orphans Neo data.** `deleteElement($entry, true)` leaves block and nested-entry element rows, Neo `structures` and `searchindex` rows behind. Soft-delete, or clean up by element-id range after checking every row is test-created.
10. **Tinker limits.** 120s transport timeout without killing PHP; sandbox blocks the literal tokens `copy(` and `file_put_contents(`; `run_query` is SELECT-only; coercive types. See `tinker-gotchas.md`.

## Review checklist

Walk this in a `/code-review` Standards pass. Each line names the rule; a "no" on a MUST is a finding.

**Composition**
- [ ] Does a new block type or module state why P-1 options 1–3 failed? (CBS-01)
- [ ] New dedicated block: data query or per-child fields a column can't carry, plus children built from atoms? (CBS-02)
- [ ] Sibling block where an option would do? (CBS-03)
- [ ] New content-driven presentation: holds every time, and listed in the project's automatic rules? (CBS-04)
- [ ] Any new editor-chosen variant implemented as an Extra Classes value, or a script writing `extraClasses`? (CBS-05)
- [ ] CTA / link / image / heading markup rendered through atoms, no inline copy? (CBS-06)
- [ ] New heading field without a UI that needs it (could a composed text module do the job?), a heading that splits off a block's own WYSIWYG copy, or an inline H2-plus-link row? (CBS-07, CBS-08)
- [ ] Eyebrow as a field; CKEditor styles outside the allowed set; hard-coded colors in CKEditor CSS; open `htmlSupport`, Source for non-admins, or HTML Embed? (CBS-09, CBS-10)
- [ ] Hiding builder content, new `#id` selectors, new text triggers? (CBS-11)
- [ ] Content fields choosing layout; presentation fields choosing content? (CBS-12)
- [ ] New field duplicating an existing handle; stale `instructions`; fields no template reads? (CBS-13, CBS-14, CBS-15)

**Schema**
- [ ] `config/project/` changes came from a tool; apply is a no-op and diff is clean on a fresh checkout? (CBS-16)
- [ ] After `create_block_type`: stub path, container fallback, drafting grid tab, `scaffoldTemplate:false`, group, order fixed? (CBS-17)
- [ ] Raw PHP used only with craft-mcp down; ends with `project-config/write`; no `*.tmp.php` committed? (CBS-18)
- [ ] DB `sortOrder` = order YAML position; every type and group listed once? (CBS-19)
- [ ] Every type has a non-null group that matches its position; no empty group? (CBS-20)
- [ ] No dangling `fieldUid`? (CBS-21)
- [ ] `lint_content_builder` clean, or the four manual checks shown? (CBS-22)
- [ ] Project content-builder page updated in the same change? (CBS-23)

**Field layouts**
- [ ] Top-level tabs are exactly `Content`, `Section Options`? (CBS-24)
- [ ] Section Options set and order match (Section Properties, Background Properties, Drafting Grid, Section Style, Custom CSS, Extra Classes); Section Style only where a variant exists? (CBS-25)
- [ ] Drafting Grid offers only Off and Lines; reveal is an automatic template rule? (CBS-26)
- [ ] Extra Classes hidden from editors; any new non-empty Extra Classes or Custom CSS value in content? (CBS-27)
- [ ] Child-only module has one `Content` tab, bar the two documented exceptions? (CBS-28)

**Templates and styles**
- [ ] Extends `body-block-open` (bar documented exceptions); carries `sectionProperties`? (CBS-29)
- [ ] Local template present for every top-level type and `columnItem` module? (CBS-30)
- [ ] Inner wrapper is `main-content {{ containerClass ?: 'container' }}`; no hard-coded width? (CBS-31, CBS-43)
- [ ] Drafting grid included first, with `block`? (CBS-32)
- [ ] Header comment present? (CBS-33)
- [ ] Empty fields render nothing; headings guarded; dropdowns read `.value`? (CBS-34, CBS-36)
- [ ] Module reads `child`? (CBS-35)
- [ ] Data block eager-loads and scopes its query? (CBS-37)
- [ ] No dead template or phantom handle? (CBS-38)
- [ ] Root class is kebab-case handle (new blocks)? (CBS-39)
- [ ] Alt text: `altText` → asset `alt` → empty, never title? (CBS-40)
- [ ] Hard-coded ground colors; generic inline `<style>`? (CBS-41, CBS-42)
- [ ] JS hooks are `data-*`; content visible without JS? (CBS-44)

**Accessibility**
- [ ] Background video attributes, new-tab links, widget semantics, unique non-empty ids, iframe titles? (CBS-45, CBS-46, CBS-47, CBS-48)

**Content writes**
- [ ] `describe_content_builder` called first? (CBS-49)
- [ ] Payload shape valid; update sent complete sets; new block Table omitted or full; read-back counts recorded? (CBS-51, CBS-52, CBS-53, CBS-54)
- [ ] Batches < 50; no blind retry after a timeout? (CBS-55)

Not reviewed: H1 count (P-6).
