---
type: log
timestamp: 2026-08-03
---

# 2026-08-03 — the "HTTP transport missing tools" bug does not exist

**Operation:** log (investigation + supersedence). **Scope:** investigated the open bug recorded on 2026-07-31 claiming entry write tools were absent from the HTTP transport. It was a verification error, not a defect. No code changed.

## What was claimed

The 2026-07-31 wrap recorded, as an open pre-existing bug needing investigation:

> Entry write tools (`create_entry`, `update_entry`, `delete_entry`, `tinker`) not exposed in HTTP transport tool list — pre-existing issue, not caused by these changes.

`STATE.md` carried it as a next step and an open question ("tool-count cap, registration filter, or config?").

## What is actually true

**All tools are registered and advertised.** `tools/list` is paginated at 50 by the MCP SDK. Walking the cursor on kcma.ddev.site (v1.5.1):

- page 1 → 50 tools, `nextCursor: "NTA="` (base64 of `50`)
- page 2 → 25 tools, `nextCursor: null`
- **75 total, 0 duplicates.** `create_entry`, `update_entry`, `delete_entry`, `tinker`, `describe_content_builder`, `get_block_type`, `get_form` all present.

The four "missing" tools sort alphabetically onto page 2. The original verification issued a single un-paginated request, saw 50 tools ending at `upload_asset`, and read the truncation as absence.

Root cause of the page size: `vendor/mcp/sdk/src/Server/Builder.php:100` — `private int $paginationLimit = 50`, the SDK default. This plugin does not override it. Spec-compliant behavior; compliant clients (including Claude Code) follow the cursor.

## The tell that was missed

In the very same 2026-07-31 session, `get_block_type` and `describe_content_builder` were **called successfully** while also being absent from the 50-tool list. A tool that dispatches but does not appear in the list can only be a listing artifact — dispatch and advertisement read the same registry. That contradiction was visible in the session transcript and should have pre-empted filing the bug.

Meta-lesson, and the reason this is worth a page rather than a one-line correction: **a negative result from a hand-rolled protocol client is evidence about the client until the client is proven correct.** Two other curl-level defects surfaced in the same investigation — an unanchored `grep -i 'mcp-session-id'` that matched the `access-control-expose-headers` line and extracted a header name instead of the session UUID, and the mandatory `Mcp-Session-Id` header on every non-`initialize` call. Three client bugs, zero server bugs.

## Durable knowledge captured

New page: [../architecture/mcp-transport.md](../architecture/mcp-transport.md) — pagination behavior and page size with cited source lines, the false-bug trap and how to verify correctly, the curl-level traps, and the SIGHUP-vs-`reload_mcp` distinction.

## Supersedes

- [2026-07-31-kcma-fixes-and-verification.md](2026-07-31-kcma-fixes-and-verification.md) — its "Entry write tools not in HTTP transport tool list" item (in both *Live verification* and *Still pending*) is **withdrawn**. That log is append-only and keeps its original wording; a forward-pointer to this entry was added.
- `STATE.md` — the next-step item and the open question were removed, since no such defect exists.

## Not changed, deliberately

Raising `setPaginationLimit()` above the tool count would make listing a single round trip and remove the footgun for any non-compliant consumer. Not done: pagination is correct as-is and compliant clients handle it, so this is a consumer-ergonomics judgment call rather than a fix — left for the operator to decide. Flagged in the session, not actioned.

## Cross-references

- [../architecture/mcp-transport.md](../architecture/mcp-transport.md)
- [2026-07-31-kcma-fixes-and-verification.md](2026-07-31-kcma-fixes-and-verification.md)
