# Project: craft-mcp-2rm

**Type:** Craft CMS 5 plugin — MCP (Model Context Protocol) server
**Package:** `2rm/craft-mcp` · handle `mcp` · namespace `twoRivers\craft\Mcp`
**Repo:** github.com/Two-Rivers-Marketing/craft-mcp-2rm
**Fork of:** `stimmt/craft-mcp` v1.3.0 (MIT), upstream github.com/stimmtdigital/craft-mcp

## What it is

An MCP server that connects an AI assistant directly to a running Craft CMS install built on the **2RM content model** (pages = entries whose content is a tree of Neo content-builder blocks). Beyond the upstream read/query surface, the 2RM fork adds a write and scaffold layer.

## What the fork adds over upstream

- **Neo content-builder** (conditional on `benf/craft-neo`): schema introspection (`describe_content_builder`, `get_block_type`), a full write suite (`create_neo_block` incl. nested trees/positioning, `update_neo_block`, `reorder_neo_blocks`, `delete_neo_block`), and scaffolding (`create_block_type`).
- **Assets:** `upload_asset`.
- **Freeform** (conditional on `solspace/craft-freeform`): read tools + `delete_submission` / `export_submissions`. No form-creation write side yet — see [plans/create-form-tool.md](../plans/create-form-tool.md).
- **Rebrand:** namespace `twoRivers\craft\Mcp`; installer bin path `vendor/2rm/craft-mcp/bin/mcp-server`.

These arrived via a nightshift run (issues #5–#12); test suite 177 → 417 at the time.

## Current focus: live-QA

The entire Neo/Freeform/asset **write** surface was built duck-typed with **no live plugin install** in the dev env, so none of it had run against real Neo/Freeform until now. The active effort is a live-QA pass against the mbd install (Craft 5.10.5, Neo 5.5.10, Freeform 5.15.16), fixing what breaks, in this priority order:

1. Freeform — **done** (4 bugs fixed; see [architecture/freeform-integration.md](../architecture/freeform-integration.md))
2. Neo multi-level tree writes — **done** (write suite fixed; see [architecture/neo-integration.md](../architecture/neo-integration.md))
3. Neo scaffolding (`create_block_type`) — **done** (handle casing + stale-memo fixes; see [architecture/neo-integration.md](../architecture/neo-integration.md))
4. Asset upload (GCS volume) — next
5. Neo childBlocks / positioning edge cases

Features and gaps surfaced along the way are tracked in [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md).

## How mbd consumes it

mbd (the primary 2RM site) wires this fork in as a **Composer path repo with a symlink** (`vendor/2rm/craft-mcp` → `_plugins/craft-mcp-2rm`), so edits here are live in mbd immediately — no publish step. Long-term intent is to consume it as a versioned dependency instead. The MCP server is a long-running process: **code changes require a SIGHUP restart** of `bin/mcp-server` (via `SignalHandler`); `reload_mcp` only detects newly installed plugins, not code.

## Cross-references

- [decisions/index.md](../decisions/index.md)
- [architecture/freeform-integration.md](../architecture/freeform-integration.md)
- [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
