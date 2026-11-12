---
type: log
timestamp: 2026-11-12
---

# 2026-11-12 — 3.0 direction: register into Craft's native MCP server

**Operation:** ingest (decision + plan). **Scope:** after reviewing Craft 5.12.1's native MCP server and upstream `stimmtdigital/craft-mcp` (v1.3.0 fork point → v2.0.0-beta.1), the operator decided 3.0 drops the plugin's own server and registers its tools into Craft's.

## Written this pass

- New [../decisions/active/2026-11-12-register-into-craft-native-mcp.md](../decisions/active/2026-11-12-register-into-craft-native-mcp.md): the decision, the `trm_` prefix (and why not `2rm_`), the user-permission access model, 2.x as security-fix-only.
- New [../plans/v3-native-mcp-migration.md](../plans/v3-native-mcp-migration.md): tool disposition (87 → 56), access tiers, code changes, tests, phases.
- `decisions/index.md` and `plans/index.md` updated.

## Also this session, not yet merged

- Branch `fix/config-secrets-and-publish-validation` (local commit `4eaa09e`, not pushed): `get_config` redacts credentials (`support/Secrets`, ported from upstream) and `publish_draft` validates the draft as live content before applying. Intended as v2.0.2, after which `2.x` is cut.

## Not written this pass

- v2.0.0 (handle rename to `mcp-2rm`, `mcp/sdk ~0.8.1 || ~0.9.0`) and v2.0.1 (`GatedDiscoveryLoader`) shipped 2026-11-11 with no wiki entry. [../overview/project.md](../overview/project.md) still gives the handle as `mcp`, and [../architecture/mcp-sdk-0-7-upgrade.md](../architecture/mcp-sdk-0-7-upgrade.md) still describes `^0.7.1`. Both are stale and need a supersede pass.
