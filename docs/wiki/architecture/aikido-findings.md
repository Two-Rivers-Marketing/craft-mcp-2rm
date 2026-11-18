---
type: note
timestamp: 2026-11-18
description: Aikido open findings for craft-mcp-2rm as of the 2026-11-17 feed pull (10 open, 1 critical), plus the .aikido scan config added that day. Unverified against code.
tags: [security, aikido, scanning, github-actions, tinker]
source: "Aikido feed via MCP aikido_issues_list, workspace Two-Rivers-Marketing, pulled 2026-11-17; .aikido commit 47e78f7 on main"
confidence: reported
---

# Aikido findings — craft-mcp-2rm (feed snapshot 2026-11-17)

**Confidence: reported.** This is Aikido's feed state as pulled on 2026-11-17. It has **not** been verified against the code: whether each finding is reachable, already fixed, or a false positive is unchecked.

## Scanning setup

- Aikido workspace "Two-Rivers-Marketing" scans this repo (branch `main`).
- 2026-11-17: a repo-root `.aikido` was added (commit `47e78f7` on `main`, made through the GitHub contents API, message ends `[skip ci]`). It excludes only `docs/wiki/raw` and `storage/import-tmp` (plain substring match; the second path is KCMA-specific and a no-op here). It deliberately keeps scanning `config/project/`, `.shift/` and `scripts/` because those can hold real credentials.
- "Display Licenses in Feed" is turned off workspace-wide, so proprietary-license findings no longer show.
- The snapshot below predates the `.aikido` file, so some rows may drop out on the next scan.

## Triage status

Nobody has triaged this repo's findings yet. Next step: audit each critical and high finding against the code (is it reachable? already fixed? false positive?), fix the real ones, and ignore false positives in the Aikido UI with a written reason.

## Findings

Snapshot: Aikido feed via MCP `aikido_issues_list`, pulled 2026-11-17 (before the `.aikido` file landed). Feed state only.

Nine of the ten findings are in `.github/workflows/` (unpinned third-party actions, the `shivammathur/setup-php` advisory, broad permissions, persisted checkout credentials). The one critical is `src/tools/TinkerTools.php:140`, an `eval` in the Tinker tool; whether that is intended behavior for a developer-facing MCP tool and how it is gated is exactly what the audit has to settle. Counts come from the table, not from the code.

Total open: 10. By severity: critical 1, high 4, medium 3, low 2. By type: sast 8, open_source 2

| Sev | Type | Title | File:line | Package / version → fix | Issue |
|---|---|---|---|---|---|
| critical | sast | Unsafe eval usage can lead to remote code execution | `src/tools/TinkerTools.php:140` |  | [395711230](https://app.aikido.dev/issues/single/395711230?groupId=121273) |
| high | sast | 3rd party Github Actions should be pinned | `.github/workflows/release.yml:45` |  | [395711229](https://app.aikido.dev/issues/single/395711229?groupId=121273) |
| high | sast | 3rd party Github Actions should be pinned | `.github/workflows/release.yml:38` |  | [395711228](https://app.aikido.dev/issues/single/395711228?groupId=121273) |
| high | sast | 3rd party Github Actions should be pinned | `.github/workflows/release.yml:23` |  | [395711227](https://app.aikido.dev/issues/single/395711227?groupId=121273) |
| high | sast | 3rd party Github Actions should be pinned | `.github/workflows/ci.yml:131` |  | [395711225](https://app.aikido.dev/issues/single/395711225?groupId=121273) |
| medium | open_source | GHSA-5wxr-w449-57cm in shivammathur/setup-php | `.github/workflows/release.yml` | shivammathur/setup-php 2 → 2.37.1 | [972420118](https://app.aikido.dev/issues/single/972420118?groupId=121273) |
| medium | open_source | GHSA-5wxr-w449-57cm in shivammathur/setup-php | `.github/workflows/ci.yml` | shivammathur/setup-php 2 → 2.37.1 | [972420114](https://app.aikido.dev/issues/single/972420114?groupId=121273) |
| medium | sast | Overly Broad Permissions in GitHub Actions Workflows is risky | `.github/workflows/release.yml:9` |  | [395711226](https://app.aikido.dev/issues/single/395711226?groupId=121273) |
| low | sast | GitHub Action actions/checkout persist Git credentials in workflow | `.github/workflows/release.yml:18` |  | [395711087](https://app.aikido.dev/issues/single/395711087?groupId=121273) |
| low | sast | GitHub Action actions/checkout persist Git credentials in workflow | `.github/workflows/ci.yml:128` |  | [395711085](https://app.aikido.dev/issues/single/395711085?groupId=121273) |

## Related

- [mcp-sdk-0-7-upgrade.md](mcp-sdk-0-7-upgrade.md) — the earlier dependency-advisory fix (CVE-2026-53965) and the note that this repo's own CI does not block on advisories.
- [Log entry for this ingest](../log/2026-11-18-aikido-findings.md)
