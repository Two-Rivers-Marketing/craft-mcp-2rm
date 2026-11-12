---
type: log
timestamp: 2026-11-12
---

# 2026-11-12 — Phase 0 spike on slipnot; v2.0.2 released; Dependabot removed

**Operation:** log (ingest). **Scope:** architecture (new page), plans (Phase 0 result, open questions).

## What happened

- **Phase 0 spike** run on slipnot.ddev.site (Craft 5.12.1, Neo 5.5.13, Freeform 5.18.2) over `craft mcp/serve --user=admin`. New [../architecture/craft-native-mcp.md](../architecture/craft-native-mcp.md) holds the results; [../plans/v3-native-mcp-migration.md](../plans/v3-native-mcp-migration.md) is updated. Headline: Craft's native tools read and write Neo block trees, plugin tool classes register into Craft's server unmodified, and Neo 5.5.13 crashes when an existing block is edited in a draft of an existing entry over stdio.
- Site footprint: one draft of the home page and one scratch unpublished draft were created and deleted through `drafts_delete`; the canonical home page was confirmed unchanged. `app/storage/runtime/trm-spike-serve.php` (gitignored) is left behind as an emptied stub because `rm` is blocked in this environment.
- **v2.0.2 released**: PR #52 merged and tagged. The live check of `publish_draft` refusing an invalid draft was not done before tagging.
- **Dependabot version updates removed** (PR #54); #45 and #46 closed.
- Issues #38 (duplicate `sortOrder`) and #42 (`agents-md --print` / `--vendor-path`) were handed to two Opus agents in separate worktrees; not merged at the time of writing.

## Follow-ups

- Report the `Request::actionSegments` crash to Neo (`Field.php:1690`, 5.5.13).
- Test whether the plugin's `update_neo_block` works in the same draft-over-stdio case.
- Decide the open questions now listed in the plan.
