---
type: log
timestamp: 2026-11-18
description: Recorded the Aikido feed state for craft-mcp-2rm (10 open, 1 critical) and the .aikido scan config added 2026-11-17. Nothing triaged or fixed.
tags: [security, aikido, scanning]
source: "Aikido feed via MCP aikido_issues_list, pulled 2026-11-17; .aikido commit 47e78f7 on main"
confidence: reported
---

# 2026-11-18 — Aikido findings and .aikido config

**Operation:** ingest. **Scope:** security scanning. Aikido (workspace "Two-Rivers-Marketing") scans this repo; on 2026-11-17 a repo-root `.aikido` was added to `main` as `47e78f7` (GitHub contents API, `[skip ci]`).

## Written this pass

- New [../architecture/aikido-findings.md](../architecture/aikido-findings.md): the 10 open findings as the feed reported them on 2026-11-17, plus the scan setup. Pointer added in [../architecture/index.md](../architecture/index.md).

## State

- The findings are **reported, not verified**. No one has audited them against the code. Next: check the critical `eval` in `src/tools/TinkerTools.php:140` for reachability, pin or accept the unpinned GitHub Actions, fix the real ones, and ignore false positives in the Aikido UI with a written reason.
- The `.aikido` excludes only `docs/wiki/raw` and `storage/import-tmp` (the second is a no-op here). It keeps scanning `config/project/`, `.shift/` and `scripts/` on purpose. "Display Licenses in Feed" is off workspace-wide.
- This entry was written from a worktree on `origin/main`; the checked-out `feat/v3-native-mcp` branch was not touched.
