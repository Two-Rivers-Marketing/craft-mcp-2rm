---
type: concept
timestamp: 2026-10-11
description: The mcp/sdk ^0.6 → ^0.7.1 move (advisory CVE-2026-53965), why element loading is pinned eager, the new 4 MiB caps, and what was and was not verified.
tags: [mcp, sdk, security, advisory, stdio, http, lazy-loading]
source: "PR #35 (merged 2026-10-11, 5aff795) and its body; vendor/mcp/sdk v0.7.1 source and composer.json/CHANGELOG.md on main; firstmate wrap note craft-mcp-sdk-advisory-fix"
confidence: reported
---

# mcp/sdk 0.7 upgrade — advisory, eager loading, size caps

PR [#35](https://github.com/Two-Rivers-Marketing/craft-mcp-2rm/pull/35) merged to `main` on 2026-10-11 as `5aff795` (owner: "Just merge in what is done"). Not tagged or released; `v1.8.0` is still the newest tag.

## The advisory and the move

- `composer.json` now requires `mcp/sdk` `^0.7.1` (was `^0.6`); `composer.lock` moved `mcp/sdk` v0.6.0 → v0.7.1 and nothing else.
- Advisory: **PKSA-p9gd-j6gr-6f9t / CVE-2026-53965**, high severity — client `HttpTransport` SSE buffer growth. Affected `>=0.5.0,<0.7.1`; **fixed in 0.7.1**. The plugin never calls the sdk client, so it was not exploitable here; the cost was that Composer's default security block refused to install any plugin version requiring `^0.6` (`2rm/craft-mcp 1.8.0 requires mcp/sdk ^0.6 -> ... affected by security advisories`).
- `^0.7.1` is the narrowest range excluding the whole advisory range. sdk 0.8.x exists and was deliberately left alone — a separate upgrade.
- The owner picked this over skipping the block ("Go option 3", 2026-10-11).

## Element loading is pinned eager

sdk 0.7 made tool/prompt/resource loading **lazy by default** (its one flagged BC break). `McpServerFactory` calls `->setLazyLoading(false)` (`src/services/McpServerFactory.php:62`) to keep the old behaviour.

- **Why:** a bad registration (e.g. a site module's `#[McpTool]` on a constructor via `Mcp::EVENT_REGISTER_TOOLS`) then fails server **startup**. Lazy would let the server start, answer `initialize`, and fail on the first `tools/list` with `-32602`. Both were shown in the validation run (below). Eager is also the behaviour that was live-verified on KCMA.
- **What lazy would change:** discovery would be skipped on requests that never read the registry — mainly the HTTP transport's `initialize` and notifications — at the cost of the failure moving to request time. Lazy works here (tested); the owner may prefer it for HTTP. Not decided.

## New size caps and error behaviour (inherited, no plugin code)

| Surface | Cap | Over the cap |
| --- | --- | --- |
| stdio — one message (`StdioTransport::DEFAULT_MAX_LINE_BYTES`) | 4 MiB | Line **discarded** with a log warning (`StdioTransport discarded an input line exceeding the maximum length.`). **No reply** — the client waits and times out. |
| HTTP — one body (`StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES`) | 4 MiB | `413`, `Request body exceeds the maximum allowed size of 4194304 bytes.` |
| HTTP — malformed `Mcp-Session-Id` | — | `400`, `Mcp-Session-Id header must be a valid UUID.` (unknown but well-formed id: `404`, as before) |

No tool takes input near 4 MiB (`upload_asset` takes a server path).

## Pre-existing stdio fragmenting (not caused by 0.7)

Over stdio, a **single message of about 200 KiB or more is split into fragments and answered with parse errors** instead of being processed. Identical on sdk 0.6.0 and 0.7.1; 60 KiB works on both and the server recovers. So the practical stdio limit is far below 4 MiB. Cause not investigated. HTTP handled a 3 MiB body fine.

## Verification record

Run by the PR's pipeline test step in a **throwaway Craft 5 site with Neo 5.5.13** (PHP 8.4.20, Composer 2.10.3; transcripts are in the PR body). Verified live:

- Plain `composer require` of the plugin fails on the base commit (exit 2, the advisory block) and succeeds on the target with `composer audit` clean.
- Real `bin/mcp-server` over stdio: 87 tools, Neo tool calls that created and read back a block type.
- HTTP `/mcp`: `403` without token, session reuse, `400` malformed session id, `404` unknown id, `413` at 4,195,429 bytes, `200` at 3,145,837 bytes.
- Bad-registration startup test with a mutation check (removing `setLazyLoading(false)` makes startup succeed and the error appear on `tools/list`).
- CI on the PR: 4 of 4 green; 608 tests reported passing (STATE.md said 606).

**Not verified by anyone:** a real consuming site (KCMA, mbd, ISF 2027); the Freeform, Commerce, Navigation and SEOmatic conditional tools; SIGHUP restart; `reload_mcp`. Re-verify on a real site before trusting it there — see [mcp-transport.md](mcp-transport.md) for the restart rules.

## Cross-references

- [mcp-transport.md](mcp-transport.md) — pagination (`setPaginationLimit` is `Builder.php:246` on 0.7.1), SIGHUP vs `reload_mcp`
- [../log/2026-10-11-mcp-sdk-advisory-fix.md](../log/2026-10-11-mcp-sdk-advisory-fix.md) — the ingest event and open follow-ups
- [../../../STATE.md](../../../STATE.md) — follow-ups tracked under Next steps
