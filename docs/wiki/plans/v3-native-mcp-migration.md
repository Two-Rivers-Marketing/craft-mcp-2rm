---
type: brief
timestamp: 2026-11-12
description: Build spec for 3.0 — register the plugin's tools into Craft 5.12's native MCP server, prefix them trm_, add user-permission checks, delete the plugin's own server.
tags: [v3, mcp-server, craft-5-12, permissions, spec]
source: "Session 2026-11-12: Craft 5.12.1 source (vendor/craftcms/cms/src/mcp), stimmtdigital/craft-mcp v2.0.0-beta.1, this repo at b45f5d0"
confidence: inferred
---

# 3.0 build spec: register into Craft's native MCP server

Decision and rationale: [../decisions/active/2026-11-12-register-into-craft-native-mcp.md](../decisions/active/2026-11-12-register-into-craft-native-mcp.md). This page is the how; work is tracked in GitHub issue [#53](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/issues/53). Nothing here is built yet. Tool counts and Craft behaviour were read from source, not run.

## How Craft's server takes plugin tools

- `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES` (`RegisterComponentTypesEvent`): add class names to `$event->types`. Classes must be instantiable; Craft resolves them through its own DI container and reads the SDK's `#[McpTool]`, `#[McpResource]`, `#[McpResourceTemplate]`, `#[McpPrompt]` attributes.
- A duplicate tool name, prompt name or resource URI throws `LogicException` and the server does not start.
- Craft filters each capability per request, in this order: `#[RequiresAdmin]` / `#[RequiresAdminChanges]` / `#[RequiresPermission]` on the method, then the OAuth token scope (HTTP only), then `EVENT_DISCOVERING_CAPABILITY`, where a plugin can set `$event->allowed = false`. A capability that is not discovered is not callable.
- Scope rule: admin-attributed needs `mcp:admin`; a tool **without** `ToolAnnotations(readOnlyHint: true)` needs `mcp:write`; the rest need `mcp:read`. Every read tool must carry `readOnlyHint` or read-only tokens cannot see it.
- Stdio (`craft mcp/serve --user=…`) has no token scope and flushes project config after each response (5.12.1, `StdioEventDispatcher`).
- `CollectingServerInstructions` (on `craft\mcp\AdminInstructions`) appends to the instructions sent on connect.

## Tool disposition (87 today → 56 kept)

New name = `trm_` + current name. Product docs get the full old→new map (`UPGRADE.md`).

**Deleted, Craft covers them (31):**

| Ours | Craft |
| --- | --- |
| `list_entries`, `get_entry`, `create_entry`, `update_entry`, `delete_entry` | `elements_list/get/create/update/delete` |
| `create_draft`, `list_drafts` | `drafts_create`, `drafts_list` |
| `list_assets`, `get_asset`, `upload_asset` | `elements_list/get`, `assets_create` |
| `list_volumes`, `list_asset_folders` | `configuration_list` (volumes), `asset_folders_list` |
| `list_users`, `get_user`, `create_user`, `update_user` | `elements_*` (users) |
| `list_sites`, `get_site`, `list_site_groups`, `list_sections`, `list_fields` | `configuration_list/get` |
| `list_plugins`, `get_system_info` | `plugins_list`, `info_get` |
| `get_queue_jobs`, `list_queue_jobs`, `retry_failed_jobs`, `clear_caches` | `queue_status`, `queue_retry_all`, `caches_clear` |
| `get_project_config_diff` | `project_config_diff` |
| `get_mcp_info`, `list_mcp_tools`, `reload_mcp` | `info_get`; `php craft mcp-2rm/check` |

**Kept (56), by access tier:**

| Tier | Tools | Gate |
| --- | --- | --- |
| Read | Neo `describe_content_builder`, `get_block_type`; `list_categories`; `list_globals`, `get_global_set`; Freeform `list_forms`, `get_form`, `list_submissions`, `get_submission`; Navigation `list_navs`, `get_nav`; `get_seo`; Commerce ×6 | `readOnlyHint`; acting user's permission checked in the handler |
| Content write | Neo `create/update/reorder/delete_neo_block`; `update_global_set`; Navigation `create/update/delete_node`; `update_seo`; `delete_submission`, `export_submissions`; Freeform `create/update/delete_form`; `publish_draft` | Acting user's permission checked in the handler; `destructiveHint` on deletes |
| Project config | `create_block_type`, `create_nav` | `#[RequiresAdminChanges]` |
| Dev | Database ×4; `get_deprecations`, `explain_query`, `get_environment`, `list_event_handlers`; `get_config`, `read_logs`, `get_last_error`, `list_console_commands`, `list_routes`; GraphQL ×4; backups ×2; `render_template`; `tinker` | `#[RequiresAdmin]`; `readOnlyHint` where true |

`publish_draft` stays because Craft's `drafts_apply` does not validate the draft as live content (per upstream's 2.0 notes; confirm in Phase 0).

## Access rules

- **Permission checks live in the handler**, not only at discovery, so the same handler is safe when called from somewhere other than the MCP transport (see "Later" below). Add one `support/Access` helper that takes the acting user from `Craft::$app->getUser()->getIdentity()` and throws `ToolCallException` on refusal.
- **Element-backed tools** use `Craft::$app->getElements()->canView/canSave/canDelete($element, $user)`, which defers to each element class (Neo blocks to their owner, Freeform submissions and Navigation nodes to their plugins). Neo writes check `canSave` on the top-level owner entry. `publish_draft` checks `canSave` and `canSaveCanonical`, as `ElementsController::actionApplyDraft()` does.
- **Non-element tools** (Freeform forms, SEOmatic settings, Commerce config reads) use the owning plugin's permission strings through `$user->can()`. Those plugins are not in this repo's `vendor/`; read the permission names from their source on a consuming site before writing the checks. Do not guess them.
- Do not depend on `craft\mcp\Authorization` or `craft\mcp\McpActor`; they carry no `@since` and are not documented API.
- **`dangerous` is re-audited.** It currently marks content writes such as `publish_draft` and `delete_form`, which production hides by default. In 3.0 it marks only Dev-tier tools that bypass permissions (`tinker`, `run_query`, `render_template`, `execute_graphql`, `create_backup`). Content writes are governed by user permissions and scopes.
- **Settings kept:** `enabled`, `disabledTools` (new names), `enableDangerousTools`, `builderFieldHandle`, `templateStubPath`. **Removed:** `httpTransport`, `allowedIps`, `logLevel`, `disabledPrompts`, `disabledResources`. Production no longer turns the plugin off; it defaults `enableDangerousTools` to `false` unless the config file sets it.
- The 1.x `config/mcp.php` fallback is removed: that file now configures Craft's server.

## Resources and prompts

- Keep the conventions resources, renamed out of Craft's URI namespace: `trm://conventions` and `trm://conventions/{topic}`. Update `AgentsMdBlock` and the server instructions to match.
- Delete the nine prompts and the `craft://config/*`, `craft://schema/*`, `craft://entries/*` resources. Craft serves its own schema resources, and the prompts teach workflows over deleted tools.

## Code changes

- **Add:** `src/integration/Capabilities.php` (class list; optional-plugin classes only when `isAvailable()`), `src/integration/Gate.php` (on `EVENT_DISCOVERING_CAPABILITY`: applies `enabled`, `disabledTools`, `enableDangerousTools` to this plugin's namespace only, never to Craft's tools), `src/support/Access.php`, `src/console/controllers/CheckController.php`.
- **Delete:** `bin/mcp-server`, `src/controllers/McpController.php`, `src/services/McpServerFactory.php`, `ToolRegistry`, `PromptRegistry`, `ResourceRegistry`, `McpRegistry`, `src/support/GatedDiscoveryLoader.php`, `SignalHandler`, `PluginReloader`, `Psr11ContainerAdapter`, `src/completions/`, `src/prompts/`, `src/events/Register*Event.php`, `McpPromptMeta`, `McpResourceMeta`, the deleted tool classes, and the `bin` entry in `composer.json`.
- **Change:** `composer.json` to `craftcms/cms ^5.12.1` and `mcp/sdk ~0.9.0`. `InstallController` and the client writers emit `php craft mcp/serve --user=…` (DDEV-wrapped as today) and take `--user`. `src/tools/` can stay where it is; nothing scans it any more.
- **`mcp-2rm/check`** reports: Craft's MCP server enabled, Craft has OAuth signing keys, no name or URI collision between this plugin and Craft, no leftover 1.x/2.x settings. Non-zero exit on failure.

## Tests (boot-free, as today)

- Every `#[McpTool]` name starts with `trm_`; no name or URI equals one in `vendor/craftcms/cms/src/mcp/capabilities`.
- Every tool method carries exactly one access marker: `#[RequiresAdmin]`, `#[RequiresAdminChanges]`, or a call into `Access` (assert by source scan, the pattern `GatedDiscoveryLoaderTest` already uses).
- Read-tier and read-only Dev tools carry `readOnlyHint`; `dangerous` appears only on Dev-tier tools.
- `Gate` admits non-plugin references untouched and refuses per setting.
- Tool count is 56; optional-plugin classes are absent when the plugin is.

## Phases

0. **Spike on a 5.12.1 site with Neo and Freeform.** Turn on Craft's server. Check: `elements_get` / `elements_update` on an entry with a Neo field; a class registered from this plugin shows up over stdio and HTTP; `drafts_apply` validation behaviour; the optional plugins' permission names. Outcome decides whether a Neo tree read tool is needed (issue #40) and whether `EntryTools` can go entirely.
1. Foundation: constraints, `Capabilities`, `Gate`, `Access`, wiring in `Mcp::init()`.
2. Tools: prefix, annotations, access, `dangerous` re-audit, delete overlapping tools.
3. Remove the server and its support code; rename conventions resources.
4. Installer, `mcp-2rm/check`, connect-time instructions.
5. Product docs, conventions, `AGENTS.md` block, `CLAUDE.md`, `UPGRADE.md` with the name map.
6. Live QA, then tag 3.0.0.

Before Phase 1 lands on `main`: merge the 2.0.2 security fixes, tag v2.0.2, cut `2.x` from that tag.

## Later, not in 3.0: chat inside the control panel

The intended follow-on is a chat feature in the Craft control panel that calls these tools as the signed-in user, on staging and production. Constraints 3.0 must not break: authorization inside handlers; handlers do not require `RequestContext` or stdio; content tools work with `allowAdminChanges` off and without `enableDangerousTools`. The chat's tool list can come from Craft's per-user capability discovery, so it sees exactly what an MCP client for that user would.

## Open questions

- Does Craft's `elements_get` return Neo block trees with field values, IDs only, or nothing? (Phase 0.)
- Do the 2.x legacy-`mcp`-handle cleanup and `Mcp::LEGACY_HANDLE` stay in 3.0? Default: yes, sites can jump from 1.x.
- [qa-feature-backlog.md](qa-feature-backlog.md) items that target deleted tools need closing or re-pointing at Craft's equivalents.
