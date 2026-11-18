# Architecture — Index

| Page | Description |
| --- | --- |
| [freeform-integration.md](freeform-integration.md) | Freeform 5.15 API shape, the 4 live-QA bugs fixed, and strict_types/count() + psysh lessons |
| [neo-integration.md](neo-integration.md) | Neo 5.5 write path — serialized-data save fix, cache hazards, template resolution gotcha |
| [asset-integration.md](asset-integration.md) | Asset upload on GCS volumes — verified, no adapter-specific defects |
| [mcp-transport.md](mcp-transport.md) | HTTP/stdio tool advertisement — `tools/list` pagination, the false-bug trap, SIGHUP vs `reload_mcp` |
| [mcp-sdk-0-7-upgrade.md](mcp-sdk-0-7-upgrade.md) | `mcp/sdk` ^0.7.1 move (CVE-2026-53965), eager loading pin, 4 MiB caps, what was/wasn't verified |
| [craft-native-mcp.md](craft-native-mcp.md) | Craft 5.12.1's native MCP server with Neo fields and plugin-registered tools, verified live on slipnot; the Neo draft-edit crash over stdio; optional plugins' permission names |
| [aikido-findings.md](aikido-findings.md) | Aikido open findings as of 2026-11-17 (10 open, 1 critical `eval` in `TinkerTools.php`, 9 in GitHub workflows) and the `.aikido` scan config. Feed state, not verified against code |
