---
type: Convention
title: Tinker and Query Gotchas
description: Runtime constraints, sandbox limitations, and timeout behavior for the tinker and run_query MCP tools.
tags: [mcp, tinker, run-query, sandbox, timeout, psysh]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# Tinker and Query Gotchas

## Psysh dependency

`tinker` shells out to psysh under the hood. If `psy/psysh` isn't installed in the target Craft project, the call fails with MCP error `-32603` (internal error) rather than a clear "missing dependency" message. Before assuming `tinker` is broken, verify with `composer show psy/psysh` in the consuming project (e.g. `/Users/justinl/repos/mbd` or `/Users/justinl/repos/kcma`) — a missing package, not a tool bug, is the usual cause.

## Timeout behavior

The MCP transport enforces a **120s timeout** on the `tinker` call, but the underlying PHP process keeps running server-side after that deadline — it isn't killed. For long-running operations (bulk saves, big loops), `tinker` can report a timeout/failure to the caller while the write actually completes moments later on the server. Don't blindly retry on a timeout: issue a follow-up read (query the entry/asset state) to check whether the operation actually succeeded before repeating it, or you risk duplicate writes.

## Batch size

Keep saves under **50 per `tinker` call**. Larger batches risk genuine timeouts (not just the transport's apparent one) from PHP execution limits or memory pressure. Split bulk operations — asset imports, Neo tree writes, mass field updates — into multiple smaller `tinker` calls rather than one large loop, and make each batch idempotent (check current state before writing) so a partial batch can resume safely.

## Sandbox restrictions

`tinker`'s sandbox blocks the literal tokens `copy(` and `file_put_contents(` in user-submitted code. This is a **token-level filter, not a capability check**: Craft's own internal asset pipeline uses these primitives freely under the hood, so code that sets `Asset::$tempFilePath` and calls `Craft::$app->elements->saveElement($asset)` works fine even though it ultimately moves a file on disk. Don't read a `-32603`-style rejection here as "tinker can't touch the filesystem" — it means your code contains a blocked token, and the fix is to route the operation through Craft's element API instead of raw PHP I/O.

## run_query is read-only

`run_query` only accepts `SELECT` statements and returns raw DB rows, not Craft elements — no field-normalization, no Neo/Matrix hydration, no permission or validation hooks. Use it for inspection and diagnostics only. Any write — including anything that looks like a simple `UPDATE` — has to go through `tinker` using Craft's element API (`saveElement`, `setFieldValue`, etc.) or a dedicated MCP write tool; never through raw SQL.

## Strict types gap

`tinker` runs in **coercive mode** — no `declare(strict_types=1)` — so type-loose code that would throw in the real app runs fine there. For example, a Yii `count()` or query helper returning the string `'5'` works in a coercive comparison inside `tinker` but throws a `TypeError` once that same logic runs under `strict_types` in production code. Treat `tinker` as a scratchpad for exploring Craft state, not as verification for typed logic — confirm type-sensitive fixes with Pest tests, which run under the project's real strictness settings.
