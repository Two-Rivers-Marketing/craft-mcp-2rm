---
type: concept
timestamp: 2026-11-12
description: How Craft 5.12.1's native MCP server behaves with Neo fields and with plugin-registered tools, verified live on slipnot (Phase 0 spike for 3.0).
tags: [craft-5-12, mcp-server, neo, stdio, permissions, verification, v3]
source: "Live run on slipnot.ddev.site 2026-11-12 (Craft 5.12.1, mcp/sdk 0.9.0, Neo 5.5.13, Freeform 5.18.2, Navigation 3.0.23, SEOmatic 5.1.22) over `craft mcp/serve --user=admin`; Craft and plugin source in that site's vendor/"
confidence: verified
---

# Craft's native MCP server: Neo fields and plugin tools

Results of the Phase 0 spike for [../plans/v3-native-mcp-migration.md](../plans/v3-native-mcp-migration.md). Everything under "Verified live" was run over stdio as an admin on slipnot. The HTTP transport, OAuth scopes and non-admin users were **not** run; statements about them are from source.

## Verified live

**Stdio needs no setup.** `php craft mcp/serve --user=admin` works with Craft's MCP server left disabled in Settings, so local use changes no project config. It lists 69 tools (the two public-server tools are absent).

**Reads return the Neo tree.** `elements_get` with `fields: ["contentBuilder"]` returns every block as a flat list in tree order (37 on slipnot's home page): `id`, `typeId`, `level`, `sortOrder`, `enabled`, and the block's field values including Table rows. Limits: the block type comes as numeric `typeId`, not its handle; nested Matrix fields inside a block need a dot path; Craft caps nested elements at 100 per record. `fields: null` returns no nested fields at all.

**Writes go through the field's GraphQL mutation input.** Craft builds `elements_field_schema` and normalizes values from each field's `getContentGqlMutationArgumentType()` (`craft\mcp\schema\CustomFieldSchema`), so any field with GraphQL mutation support is writable. For Neo the shape is:

```json
{"contentBuilder": {"sortOrder": [101, 102, "new1"],
  "blocks": [{"text": {"id": "new1", "level": 1, "richText": "<p>…</p>"}}]}}
```

`sortOrder` lists every block to keep, in order; a block left out is removed. `blocks` carries only the blocks being created or changed, each keyed by its block type handle. On a scratch unpublished draft all of these worked and read back correctly: creating a three-level nested tree, editing one existing nested block while sending only that block (its level and untouched fields were kept), reordering by `sortOrder` alone, and removing by omission.

**Malformed payloads are mostly refused.** Craft validates against the JSON schema before saving and names the path: an unknown block type handle, `blocks` as an object map, and field values not nested under the type key were all rejected with nothing saved. One shape still fails silently: a new block with **no `sortOrder`** returns success and persists nothing (the same Neo behaviour as shape 5 in issue #39).

**Editing an existing block in a draft of an existing entry crashes over stdio.** `elements_update` on a draft created by `drafts_create`, changing a block that still belongs to the canonical entry, returns JSON-RPC `-32603` and saves nothing. Cause: Neo 5.5.13 `Field.php:1690` reads `Craft::$app->getRequest()->actionSegments`, which exists only on a web request. The branch is reached only when the owner is a derivative (a draft of an existing entry) and the block is not yet a draft, so unpublished drafts and canonical entries are unaffected, and so is appending a new block to such a draft (verified). By the same reading it should not occur over HTTP; not run.

**A draft shares block ids with its canonical entry** until a block is changed.

**Plugin tool classes register unmodified.** A throwaway script added `NeoSchemaTools`, `SystemTools` and `FreeformTools` (v2.0.1, untouched) on `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES`. Craft's stdio server then listed 82 tools, and `get_block_type`, `describe_content_builder`, `get_config` and `list_forms` returned real data. A handler on `EVENT_DISCOVERING_CAPABILITY` setting `allowed = false` for `read_logs` removed it from the list and made calling it fail with `Tool not found`.

## From source, not run

- `drafts_apply` checks `save` and `saveCanonical` permission, then calls `Drafts::applyDraft()` with no live-scenario validation first. `elements_validate` validates under the live scenario as a separate call.
- Tools without `ToolAnnotations(readOnlyHint: true)` need the `mcp:write` scope over HTTP. The plugin's tools carry no annotations today.

## Permission names in the optional plugins

Read from slipnot's `vendor/`, for the handler checks in 3.0:

- **Freeform:** `freeform-formsAccess`, `freeform-formsCreate`, `freeform-formsDelete`, `freeform-formsManage` (and `freeform-formsManageIndividual`), `freeform-submissionsAccess`, `freeform-submissionsRead` / `freeform-submissionsManage` (each with a per-form nested variant). `Submission::canView()` checks Read or Manage, global or per form.
- **Navigation:** `navigation-manageNav:<navUid>`, `navigation-editNav:<navUid>`, `navigation-deleteNav:<navUid>`, `navigation-createNavs`. `Node` implements `canView` / `canSave` / `canDelete`.
- **SEOmatic:** `seomatic:content-meta` and its `:general`, `:facebook`, `:twitter`, `:sitemap` children; `seomatic:global-meta…`, `seomatic:site-settings…`.
- **Neo:** `neo-createBlocks:<uid>`, `neo-editBlocks:<uid>`, `neo-deleteBlocks:<uid>`.

Commerce is not installed on slipnot; its names are still unread.

## What this changes for 3.0

- `EntryTools` can be deleted entirely apart from `publish_draft`: Craft's tools read and write Neo content.
- Issue #40 (`get_neo_tree`) is mostly answered by `elements_get`. What is left is block type handles in the output and trees over 100 blocks.
- Issue #39's validation matters less for the native path, which refuses three of the shapes itself.
- The four Neo content tools stay in 3.0 for now (operator decision, 2026-11-12), because of the stdio draft crash above; see the plan.

## Cross-references

- [../plans/v3-native-mcp-migration.md](../plans/v3-native-mcp-migration.md)
- [neo-integration.md](neo-integration.md): the plugin's own Neo write path and its hazards
- [../decisions/active/2026-11-12-register-into-craft-native-mcp.md](../decisions/active/2026-11-12-register-into-craft-native-mcp.md)
