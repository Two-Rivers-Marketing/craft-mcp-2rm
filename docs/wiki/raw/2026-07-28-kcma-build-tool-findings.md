# Field report — craft-mcp tool findings from the KCMA build

**Date:** 2026-07-28
**Source:** Claude Code session on the KCMA replatforming project (`~/repos/kcma`)
**Plugin version under test:** `v1.4.0` (`304662a`), MCP over HTTP transport
**Consumer:** Craft 5.10.12 Pro on ddev, 2RM content model, Neo `contentBuilder`, site-toolkit `^1.0`
**Authority:** raw/ — external field report, unprocessed. Not curated.

## What the session did

A real build, not a tool exercise. Over one session, against the live KCMA install:

- imported 439 company shells and 22 event shells from a crawl CSV
- created 3 section entry templates and 2 new Neo block types (`entryCard`, `entryFeed`)
- rebuilt the kcma.org homepage as 22 Neo blocks across 10 sections
- uploaded 6 images through the asset pipeline to a GCS-backed volume and attached them to Neo blocks and a Matrix background field
- diagnosed why builder content rendered invisible (unrelated to this plugin — a missing `.module` reveal script)

Roughly 60 tool calls. The findings below are the friction points, ranked by how much damage each did, with the evidence that produced them.

## Suggested destination

Per this repo's `WIKI-CLAUDE.md`, live-QA findings belong in `plans/qa-feature-backlog.md`, with anything committed to becoming a `decisions/deferred/` entry. Findings 1–3 look like defects rather than feature requests and may warrant `architecture/` gotcha pages instead.

---

## 1. `template.exists` is wrong in two ways, and it causes wrong conclusions

**Severity: highest.** This is not a missing feature; it reports a falsehood that a reasonable agent acts on.

Observed, for a block type that was rendering four cards on the homepage at the time of the call:

```
get_block_type(handle: "entryCard")
  → "template": { "path": "body_blocks/entryCard.twig", "exists": false }
```

Two separate bugs:

1. **Wrong path for child block types.** `entryCard` is a child of `columnItem` (`topLevel: false`). The toolkit renders child types from `_includes/columnItems/<handle>.twig`, never `body_blocks/`. The reported path does not exist and never would.
2. **No awareness of other template roots.** `site-toolkit` registers a Craft template root and every include in the consuming build is a two-element fallback array — local path first, plugin path second. A block with no local template still renders from the plugin copy. So `exists: false` conflates "no template anywhere" with "renders fine from the toolkit."

**Why it's expensive.** A prior session on this project treated `exists: false` as evidence that block types would not render and drew the wrong conclusion three times; the project handoff carries a dedicated warning about it. `describe_content_builder` reports `exists: false` for *every* block type on a stock install, which reads as "nothing is wired up" when in fact 11 of 13 block types render.

**Suggested fix.** Resolve through Craft's template loader across all registered roots rather than testing one hardcoded path, and pick the path by nesting level. Report which root serves it:

```json
"template": { "exists": true, "path": "_includes/columnItems/entryCard.twig", "servedBy": "site-toolkit" }
```

`servedBy` is the part that would have saved the most time — knowing a template resolves is useful; knowing *whose* copy is about to render is what you actually need before overriding it.

## 2. Response verbosity is a context bug at scale

Every `create_entry` and `update_entry` returns the entire SEOmatic `MetaBundle` — `metaGlobalVars`, `metaSiteVars`, `metaSitemapVars`, `metaContainers`, `metaBundleSettings` — overwhelmingly empty strings, several hundred lines per call.

For the 439-company import this was the dominant cost of the operation. The import had to be restructured into batched `tinker` calls partly to avoid it, which then meant losing the guardrails the dedicated tools provide.

**Suggested fix.** Return a compact summary by default — `id`, `title`, `slug`, `uri`, `status`, and the fields actually written — with `verbose: true` to opt into the full element dump. Bulk callers are the common case for an agent, and they are the ones the current default punishes hardest.

## 3. Nothing writes Matrix / nested-entry content

`create_neo_block` accepts a nested `children` tree for Neo blocks, which is excellent. But a **Matrix** field inside `fields` accepts only scalars, so any Matrix content needs hand-rolled PHP.

Two places this came up in one session:

- attaching an image to five media-bar blocks (`image` Matrix → `asset` + `altText`)
- attaching a hero background (`backgroundProperties` Matrix → entry type `backgroundImage`, with `image`, `backgroundRepeat`, and `backgroundPosition`/`backgroundSize` tables)

Both required constructing `craft\elements\Entry` with `ownerId`/`fieldId`/`siteId` by hand in `tinker`, and both took a failed attempt first. This was the single largest time sink of the session.

**Suggested fix.** Let `fields` accept nested payloads for Matrix, mirroring the shape `create_neo_block` already uses for Neo children:

```json
{ "image": [ { "asset": [3037], "altText": "…" } ] }
```

## 4. `create_block_type` can declare children but not parents, and scaffolds to the wrong path

Creating a child-only block type takes three steps instead of one:

```
create_block_type(name: "Entry Card", existingFields: [...])
  → blockType: { topLevel: true, childBlockTypes: [] }        ← wrong for a child type
tinker: set $blockType->topLevel = false; save
tinker: append 'entryCard' to columnItem->childBlocks; save
```

There is `childBlockTypes` to declare what a type may contain, but no way to declare what may contain *it*, and no `topLevel` parameter at all. The API is asymmetric.

Relatedly, `scaffoldTemplate` always writes to `templates/body_blocks/<handle>.twig`. For a child type that is the wrong directory (see finding 1), so the call has to be made with `scaffoldTemplate: false` and the file written by hand anyway.

**Suggested fix.** Add `topLevel` and `parentBlockTypes` (appending to the named parents' `childBlocks`), and derive the scaffold path from whether the type ends up top-level.

## 5. `newFields` cannot create relation fields

Supported types are `plainText`, `richText`, `dropdown`, `lightswitch`, `asset`. There is no `entries`, `categories`, or `users`.

For a content model whose purpose is referencing structured content, that is the hole in the middle. The session's `entryCard` block — whose entire job is to reference an entry — only worked because a generic `entry` Entries field happened to already exist in the install from the MBD lineage. On a cleaner install the block could not have been created without dropping to `tinker` or the control panel.

**Suggested fix.** Add `entries`, `categories`, `users`, with `sources` and `maxRelations` options.

## 6. No `delete_entry`

There is `delete_form`, `delete_node`, `delete_neo_block`, `delete_submission` — but no way to delete an entry. Cleaning up three smoke-test entries created to verify section templates required `tinker`.

The gap is in the operation where a guardrail matters most. A tool could refuse to delete entries with children, warn on non-empty channels, or default to soft-delete; hand-written `deleteElement($e, true)` in `tinker` does a hard delete with no checks.

## 7. Date fields shift silently on write

```
update_entry(id: 1913, fields: { "eventStartDateTime": "2026-09-14 09:00:00" })
  → read back: "2026-09-14 04:00:00"
```

A five-hour shift, reported as success. The date renders correctly; the time does not. Craft's timezone is `America/New_York`, so the input was likely treated as UTC and converted, or vice versa.

**Suggested fix.** Accept and return ISO-8601 with an explicit offset, or document which zone bare datetime strings are interpreted in. Silent shifts that report success are the worst failure mode — nothing surfaces until someone reads a wrong event time on a published page.

## 8. Smaller items

- **Inconsistent identifier parameter.** `create_neo_block` takes `entryId`; `update_entry` and `get_entry` take `id`. Cost one failed call (`Missing required properties: id`). Also `create_entry` requires `type`, which is not obvious from the name.
- **`tinker`'s security error doesn't name the blocked construct.** `SecurityError: Code contains a blocked pattern. Shell commands, file writes, eval, and unbounded output-buffer teardown loops are not allowed.` The blocked call was `copy()`, for staging a temp file before an asset upload. Naming the matched pattern — and pointing at `upload_asset`, which is the right tool — would have gone straight to the answer instead of requiring a guess.
- **`upload_asset`'s `path` must be container-visible.** The description does say "readable on the server running Craft," which is accurate and was enough. Worth an example (`/var/www/html/storage/...`) since the host path is the natural first guess under ddev.

## What worked well and should not change

- **`dryRun` on `create_neo_block`.** The flattened, leveled before/after diff caught the shape of a three-level `multiColumn > columnItem > entryCard` tree before 11 blocks were written. This is the single best feature in the toolset for agent use.
- **`dryRun` on `create_block_type`, and its field plan.** Reporting `attach` / `create` / `matched` separately meant it was visible up front that `entryCard` would reuse existing `entry`, `headline` and `richText` rather than silently creating near-duplicate fields. The dedupe-by-handle-or-similar-name behaviour is genuinely good design.
- **`describe_content_builder` returning dropdown option *values*.** The difference between guessing `"manufacturer"` and knowing it. Same for nesting rules.
- **`tinker` as an escape hatch.** Every gap above was workable because `tinker` exists. That is the right architecture; the findings are about which paths deserve to be paved.

## One observation about tool discoverability

`update_neo_block` exists and was never used this session — the headline change on a Neo block, and every nesting fix, went through `tinker` instead. Partly a caller error. But it is also a signal: when a general-purpose escape hatch is described as "the workhorse" in project onboarding, it wins the comparison against specific tools at the moment of choice, and the specific tools' guardrails go unused. Tool descriptions that state what the dedicated tool does *better* than raw PHP (validation, canonical-element targeting, dry-run) would help them compete.

## Packaging and release

Not a tool issue, but surfaced in the same session while making this repo the central source for the KCMA build.

- **HEAD sat 59 commits ahead of `v1.3.0`.** The HTTP transport that consumers' `.mcp.json` depends on was in those 59 commits and in no tag. A consumer requiring `^1.3` would have silently rolled back the HTTP transport, navigation CRUD, `update_global_set` and `delete_form`. Tagged `v1.4.0` at `304662a` to fix this. Worth tagging closer to the merges, since the untagged window is invisible to consumers and looks like a working constraint.
- **Composer needs `"no-api": true` on the VCS repository entry.** Without it, `composer update` fails with `Could not authenticate against github.com` *even though the repo is public*, because Composer calls the GitHub API for metadata. With `no-api` it uses plain git and inherits existing credentials, and no token has to be stored on disk. This is worth a line in the install docs — it is not obvious and the error message points nowhere near the fix.

## Reproduction environment

- Craft 5.10.12 Pro, PHP 8.3 (container), Composer `config.platform.php: 8.3`
- Neo 5.5.10, Super Table 4.0.6, CKEditor 4.11.4, SEOmatic 5.1.20, Freeform 5.15.16
- `bgd/craft-site-toolkit ^1.0` — registers the `site-toolkit` template root that finding 1 depends on
- Volumes `media` and `documents` on `craft\googlecloud\Fs`
- Transport: MCP over HTTP
