---
type: log
timestamp: 2026-10-11
---

# 2026-10-11 — mcp/sdk ^0.7.1: PR #35 merged, wiki brought up to date

**Operation:** log (ingest). **Scope:** [PR #35](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/pull/35) merged to `main` as `5aff795`. Moves `mcp/sdk` `^0.6` → `^0.7.1` to clear PKSA-p9gd-j6gr-6f9t / CVE-2026-53965 (affected `>=0.5.0,<0.7.1`), which made Composer's default security block refuse to install the plugin. Pins `setLazyLoading(false)`. Detail: [../architecture/mcp-sdk-0-7-upgrade.md](../architecture/mcp-sdk-0-7-upgrade.md). Not tagged or released.

## Written this pass

- New [../architecture/mcp-sdk-0-7-upgrade.md](../architecture/mcp-sdk-0-7-upgrade.md); pointer added to [../architecture/mcp-transport.md](../architecture/mcp-transport.md); `STATE.md` updated.

## Open follow-ups (recorded, none fixed here)

- **`CHANGELOG.md:15` is wrong:** it says a stdio message over 4 MiB "is now rejected". On stdio it is discarded with a log warning and no reply; only HTTP answers `413`. The PR's pipeline review flagged it as info and it merged unfixed.
- **Four places still name PHP 8.3** while `composer.json` requires `^8.4` (since `9600bab`): `docs/installation.md:10`, `docs/installation.md:254`, `CLAUDE.md:5`, `.github/workflows/release.yml:25` (`php-version: '8.3'`). Someone following the install doc on 8.3 hits a Composer platform failure. The release workflow is the repository owners' to change.
- **`README.md:20` calls the repository private** ("Craft MCP is a private fork, distributed via VCS rather than Packagist"); it is public.
- Not verified on a real consuming site, the conditional-plugin tools, SIGHUP, or `reload_mcp` (see the architecture page).
- Pre-existing: stdio messages ≥ ~200 KiB are fragmented and answered with parse errors, on the old sdk too.
- Pre-existing, outside this change: `composer.json` sets `config.policy.advisories.block: false` (since `60b047b`), so this repo's own CI never sees the block consumers hit — decide whether to keep it. `composer.lock` pins `enshrined/svg-sanitize` at a version with 3 medium advisories (`<=0.22.0`, GHSA-v383-3rw5-q8rf, from `craftcms/cms`); fresh consumer installs resolve a fixed version.
- `CHANGELOG.md` has no entries for 1.4.0–1.8.0.
- `CLAUDE.md` says `composer lint:test` fails on 4 pre-existing files; the firstmate wrap note for this PR reports it passing (157 files). Not re-run here.
- A tag/release is still needed before consumers get the fix without requiring the branch/commit.

## Cross-references

- [2026-08-03-http-transport-non-bug.md](2026-08-03-http-transport-non-bug.md) — earlier sdk/transport verification error (pagination), same family of "looks like a server fault" traps
