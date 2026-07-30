---
type: log
timestamp: 2026-07-30
---

# 2026-07-30 — KCMA field report ingest

**Operation:** ingest (raw → curated). **Source:** `raw/2026-07-28-kcma-build-tool-findings.md` — external field report from a real build session on `kcma.ddev.site`, v1.4.0 (`304662a`), MCP over HTTP transport.

**Authority:** trusted external (real build, ~60 tool calls, 439-entry import + 3 section templates + 22 Neo blocks + 6 asset uploads).

## What was curated

8 ranked findings + packaging observations from the KCMA session, integrated into:

- **[plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)** — 11 new backlog items across three new/expanded sections:
  - **Neo** (3 new): `template.exists` resolution bugs, `create_block_type` missing `topLevel`/`parentBlockTypes`, `newFields` missing relation field types
  - **Entry / content tools** (4 new, new section): response verbosity (SEOmatic dump), no Matrix writes, no `delete_entry`, date field silent shift
  - **Cross-cutting** (4 new): inconsistent identifier params, `tinker` error naming, Composer `no-api`, release tagging cadence

- **[architecture/neo-integration.md](../architecture/neo-integration.md)** — new "Template resolution gotcha" section documenting the `template.exists` two-bug root cause (wrong path for child types + no multi-root awareness)

- **[architecture/index.md](../architecture/index.md)** — updated descriptions, added `asset-integration.md` entry

## Not curated (deliberate)

- **"What worked well" section** (dryRun, field plan dedup, dropdown values, tinker escape hatch) — positive feedback, no action items, stays in raw source
- **Tool discoverability observation** — meta UX point about dedicated tools losing to `tinker` when `tinker` is described as "the workhorse"; captured implicitly by the backlog items that are each a case where `tinker` was used instead of a dedicated tool

## Cross-references

- [../raw/2026-07-28-kcma-build-tool-findings.md](../raw/2026-07-28-kcma-build-tool-findings.md)
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../architecture/neo-integration.md](../architecture/neo-integration.md)
