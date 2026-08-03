---
type: concept
timestamp: 2026-08-03
description: How the MCP server exposes tools over HTTP and stdio, and the pagination gotcha that fakes a missing-tools bug.
tags: [mcp, transport, http, pagination, verification]
source: "Live verification against kcma.ddev.site + vendor/mcp/sdk source, 2026-08-03"
confidence: verified
---

# MCP transport — HTTP, stdio, and tool advertisement

How this plugin's MCP server exposes its toolset, and the one non-obvious behavior that has already produced a false bug report.

## `tools/list` is paginated at 50

The MCP PHP SDK paginates every list method. The default page size is **50**, set in
`vendor/mcp/sdk/src/Server/Builder.php:100` (`private int $paginationLimit = 50`), and changeable via
`Builder::setPaginationLimit()` (`:242`). The same default applies to `resources/list`,
`prompts/list`, and `resourceTemplates/list` — their handlers each take `int $pageSize = 20`, which
the Builder's limit overrides.

This plugin does **not** override it. Pagination is therefore standard, spec-compliant behavior, not
a plugin concern.

Consequence: with 75 tools registered, `tools/list` returns 50 tools plus
`nextCursor` (base64 of the offset — `"NTA="` is `50`). A second request carrying that cursor returns
the remaining 25 with `nextCursor: null`.

A spec-compliant client follows the cursor and sees the whole toolset. Claude Code does.

## The false-bug trap — verify by paginating, not by one request

**A single un-paginated `tools/list` request is not evidence that a tool is unregistered.** On
2026-07-31 a raw-`curl` verification pass concluded that `create_entry`, `update_entry`,
`delete_entry`, and `tinker` were "missing from the HTTP transport" and filed it as a pre-existing
bug needing investigation. All four were registered and working the whole time — they simply sort
alphabetically onto page 2.

The tell that should have caught it sooner: `get_block_type` and `describe_content_builder` were
**successfully called** in the same session while also being absent from the 50-tool list. A tool
that dispatches but does not appear in the list is a *listing* artifact, never a registration
failure — dispatch and advertisement read the same registry.

When verifying tool availability over HTTP, walk every page until `nextCursor` is null and union the
results. Two further curl-level traps from the same session:

- `grep -i 'mcp-session-id'` on response headers also matches
  `access-control-expose-headers: Mcp-Session-Id`, so a naive `awk '{print $2}'` extracts the header
  *name* instead of the session UUID. Anchor the match: `grep -i '^mcp-session-id:'`.
- Every non-`initialize` request needs the `Mcp-Session-Id` header, or the server answers
  `-32600 "A valid session id is REQUIRED for non-initialize requests."`

## Code changes need a SIGHUP, not `reload_mcp`

The server (`bin/mcp-server`) is one long-running process. Editing plugin code does not take effect
until a **SIGHUP restart** (`SignalHandler` re-execs). The `reload_mcp` tool only detects newly
*installed plugins* — it does not pick up code edits. This is also why in-process caches go stale
across tool calls; see the cache-staleness taxonomy in
[freeform-integration.md](freeform-integration.md#cache-staleness-family-in-the-long-running-server-26--28--29--30).

Consumers pulling this plugin by Composer tag need `composer update 2rm/craft-mcp` **and** a
restart before new tools appear.

## Cross-references

- [neo-integration.md](neo-integration.md) — template resolution, another live-only failure mode
- [freeform-integration.md](freeform-integration.md) — long-running-process cache hazards
- [../log/2026-08-03-http-transport-non-bug.md](../log/2026-08-03-http-transport-non-bug.md) — the investigation that produced this page
