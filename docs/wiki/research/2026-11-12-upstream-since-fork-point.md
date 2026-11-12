---
type: note
timestamp: 2026-11-12
description: What upstream stimmt/craft-mcp shipped between our v1.3.0 fork point and v2.0.0-beta.1, and what we took, deferred or skipped.
tags: [upstream, stimmt, fork, port-candidates]
source: "stimmtdigital/craft-mcp CHANGELOG.md, UPGRADE.md and src/ at a938f82 (2026-11-10), compared with this repo at b45f5d0"
confidence: reported
---

# Upstream since the fork point (v1.3.0 → v2.0.0-beta.1)

Point-in-time comparison, 2026-11-12. Upstream is `stimmtdigital/craft-mcp` (MIT). Our repo shares no git history with it, so anything taken is a hand port. Upstream's claims below are from its changelog and source; none were run here.

## Upstream's 2.0 (beta, 2026-11-10)

Drops its own server and registers into Craft 5.12's. 36 tools remain, all prefixed `stimmt_`. Keeps handle `mcp` and works around the route clash; settings move to `config/craft-mcp.php`. This is the model for our 3.0: [../decisions/active/2026-11-12-register-into-craft-native-mcp.md](../decisions/active/2026-11-12-register-into-craft-native-mcp.md).

Pieces worth reading when building 3.0: `src/integration/Capabilities.php` (registration list), `src/integration/Gate.php` (per-request gate on `EVENT_DISCOVERING_CAPABILITY`, own namespace only), `src/integration/SigningKeys.php` (generates Craft's OAuth keys during `craft up`), `src/console/controllers/CheckController.php`.

## Taken

| Upstream | Ours |
| --- | --- |
| `get_config` redacts credentials (`support/Secrets`) | PR #52, v2.0.2. Extended beyond upstream: walks config objects, matches `db.url` as a path suffix, treats numeric values as credentials. Upstream still passes a config object through unredacted. |
| `publish_entry` validates the draft as live content (#76) | PR #52, `publish_draft`. |

## Still useful for 3.0, not taken yet

- `maxResponseBytes`: refuse a tool result over a size cap instead of emitting one the client cannot read.
- Arguments no tool declares are refused instead of dropped.
- One pagination envelope for every tool taking `limit` (`count`, `total`, `limit`, `offset`, `hasMore`).
- `refs_resolve`: natural keys (`{section, slug}`, `{volume, filename}`) to the element ids Craft's `elements_*` tools take.
- `entries_count` with per-value breakdowns; preview URLs on entry writes; `copy_entry_to_site`.
- Codex support in the installer.

## Moot once 3.0 deletes our entry and asset tools

- Date fields with "Show time zone" losing their zone on a read/write round trip (#75).
- `list_entries` status default (#73), the natural-key payload format, draft-first writes.
- The stdio fix for responses larger than the socket buffer and requests split across writes. On 2.x this matches the open follow-up in [../log/2026-10-11-mcp-sdk-advisory-fix.md](../log/2026-10-11-mcp-sdk-advisory-fix.md).
- Upload by URL with an SSRF guard. Ours takes a local path; Craft has `assets_upload_url`.

## Skipped

- The 1.5–1.8 OAuth connector, token screens, `allowedIps` CIDR ranges and ChatGPT callback: upstream removed all of them in its own 2.0.
- Lite/Pro editions.

## Cross-references

- [../plans/v3-native-mcp-migration.md](../plans/v3-native-mcp-migration.md)
- [../overview/project.md](../overview/project.md)
