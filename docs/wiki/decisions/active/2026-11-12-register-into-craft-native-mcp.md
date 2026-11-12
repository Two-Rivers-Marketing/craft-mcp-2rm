---
type: decision
timestamp: 2026-11-12
title: 3.0 drops the plugin's own MCP server and registers into Craft's
status: active
description: 3.0 removes bin/mcp-server and the HTTP transport; the plugin's tools register with Craft 5.12's native MCP server, prefixed trm_, with user-permission checks.
tags: [architecture, mcp-server, craft-5-12, permissions, v3]
source: "Operator decision in session 2026-11-12; Craft 5.12.1 source (vendor/craftcms/cms/src/mcp); stimmtdigital/craft-mcp v2.0.0-beta.1"
confidence: verified
---

# 3.0 drops the plugin's own MCP server and registers into Craft's

**Status:** active
**Date:** 2026-11-12
**Scope:** the 3.0 release line: server architecture, tool naming, access model, and what happens to 2.x
**Supersedes:** —

## Decision

1. **No server of our own.** 3.0 removes `bin/mcp-server`, the HTTP transport and the plugin's registries. Tools register with Craft's native MCP server through `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES`. Craft provides HTTP, OAuth (scopes `mcp:read`, `mcp:write`, `mcp:admin`) and stdio (`php craft mcp/serve --user=…`).
2. **Tools Craft covers are deleted**, not kept in parallel: entry, asset and user CRUD, sites, sections, fields, plugins, queue, caches, and the `get_mcp_info` family.
3. **Every remaining tool is prefixed `trm_`** (`trm_create_neo_block`). Current verb-first names are kept after the prefix.
4. **Read and content-write tools check the acting user's Craft permissions.** They stay available when `allowAdminChanges` is off. Only tools that write project config require `allowAdminChanges`. Dev tools are admin-only.
5. **2.x gets security fixes only.** A `2.x` branch is cut after v2.0.2; sites move to 3.0 as they reach Craft 5.12.1.

Build spec: [../../plans/v3-native-mcp-migration.md](../../plans/v3-native-mcp-migration.md).

## Rationale

- Craft 5.12 ships authenticated HTTP, public and stdio MCP servers with per-user permission and token-scope gating. Running a second server duplicates that with a weaker access model (`disabledTools` / `enableDangerousTools` only, no user).
- Upstream `stimmt/craft-mcp` took the same route in v2.0.0-beta.1 (2026-11-10), which confirms the extension points are sufficient.
- The prefix exists because Craft throws `LogicException` and refuses to start its MCP server when two capabilities share a name. No name collides today; the prefix removes the risk when Craft adds tools.
- `2rm_` was the first choice and was rejected: Gemini's function-calling API requires names to start with a letter or underscore, and clients that pass MCP tool names through unprefixed fail on a leading digit.
- User-permission checks, not admin-only, because the intended next step is a chat feature inside the Craft control panel that calls these tools as the signed-in user, on staging and production. That needs content tools usable by non-admins and with `allowAdminChanges` off.

## Trade-offs accepted

- 3.0 requires Craft 5.12.1+ and `mcp/sdk ~0.9.0`.
- Every convention doc, `AGENTS.md` block and site prompt that names a tool must be rewritten.
- The SIGHUP dev loop and `reload_mcp` go away with the server.
- Static-token and IP-allowlist access have no equivalent; clients authenticate through Craft's OAuth or run stdio as a named user.

## Cross-references

- [../../plans/v3-native-mcp-migration.md](../../plans/v3-native-mcp-migration.md): tool disposition, access tiers, phases
- [../../architecture/mcp-transport.md](../../architecture/mcp-transport.md): the transport this decision retires
- [../../overview/project.md](../../overview/project.md)

## Do not relitigate without

Craft removing or materially restricting `EVENT_REGISTER_CAPABILITY_TYPES` / `EVENT_DISCOVERING_CAPABILITY`, or the Phase 0 spike showing Craft's `elements_*` tools cannot read or write Neo fields in a way the Neo tools can work alongside.
