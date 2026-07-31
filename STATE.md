# STATE — craft-mcp-2rm

**Updated:** 2026-07-31
**Status:** Active — v1.5.1 released, live-verified on kcma.ddev.site

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). 532 tests, phpstan clean.

### v1.5.1 (2026-07-31) — KCMA field report fixes

7 fixes from a real KCMA build session (439-entry import, 22 Neo blocks, 6 asset uploads). Live-verified on kcma.ddev.site over HTTP transport:

- **template.exists** — resolves across all registered template roots (project + plugin), nesting-aware paths, reports `servedBy`. 15/22 block types resolve on KCMA (was 4/22).
- **Response verbosity** — `create_entry`/`update_entry` return only written fields.
- **Date format** — ISO-8601 with tz offset everywhere. Breaking: old `Y-m-d H:i:s` consumers.
- **`topLevel` param** on `create_block_type` (default `true`).
- **`entries`/`users` in `newFields`** with `sources`/`maxRelations`.
- **`delete_entry`** — new tool with `dryRun`, `dangerous: true`.
- **`tinker` error naming** — names the matched blocked construct.

## Next steps

- [ ] **Entry write tools missing from HTTP transport** — `create_entry`, `update_entry`, `delete_entry`, `tinker` not in tools/list over HTTP. Other dangerous tools show fine. Pre-existing; needs investigation.
- [ ] **Matrix/nested-entry writes** — biggest remaining gap. Matrix fields accept only scalars; nested content requires `tinker`.
- [ ] **`parentBlockTypes` param** — deferred from `topLevel` fix.
- [ ] **Merge PR #27** — #18 `get_form` fix stranded in draft PR.
- [ ] **SIGHUP mbd's MCP server** — picks up v1.5.1 for that install.
- [ ] Inconsistent identifier params (`entryId` vs `id`) — low priority, breaking.
- [ ] Composer `no-api` line in install docs.

## Open questions

- Entry write tools HTTP transport absence — tool-count cap, registration filter, or config?
