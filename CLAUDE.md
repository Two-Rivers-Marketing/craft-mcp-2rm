# CLAUDE.md

Craft CMS 5 plugin: an MCP server exposing a Craft install to AI assistants. 2RM fork of `stimmt/craft-mcp`. Package `2rm/craft-mcp`, handle `mcp`, namespace `twoRivers\craft\Mcp` (sub-namespaces lowercase: `tools`, `support`, `services`, `prompts`, `resources`, `models`, `enums`, `events`, `contracts`, `installer`).

PHP 8.3+, `declare(strict_types=1)` in every `src/` file.

## Commands

```bash
composer test                 # Pest (fast, <1s)
composer test:unit            # Unit suite only
vendor/bin/pest --filter=Foo  # single file/group
vendor/bin/pint <files>       # auto-format (lint your OWN touched files)
composer analyse              # phpstan (memory limit already set)
```

Tests need dev deps: `composer install` (pulls Craft + tooling; `vendor/` is gitignored).

## Gotchas

- **phpstan needs `--memory-limit=1G`** (OOMs at PHP's default 128M). `composer analyse` / `composer ci` already pass it; add it yourself when invoking `vendor/bin/phpstan` directly.
- **Repo-wide `composer lint:test` fails** on 4 pre-existing files (`DebugTools`, `GraphqlTools`, `TinkerTools`, `DatabaseTools`). Not yours to fix — run `vendor/bin/pint` on the specific files you touched.
- **Custom phpstan rules are enforced** (`sanmai/phpstan-rules`, level 5): `NoElse`, `NoNestedIfStatements`, `NoNestedLoops`, `RequireGuardClauses`. Write guard-clause style (early returns, no `else`, no nested `if`/loops) or analysis fails.
- **`strict_types` + Yii `count()`:** query `->count()` returns a **string**. Returning it into an `int`/`?int` throws `TypeError`. Always `(int)`-cast.
- **`tinker`/psysh runs coercive** (no `strict_types`), so it hides strict-type bugs. Verify typed fixes with Pest, not only tinker.

## Architecture

- **Tools are discovered by scanning `src/tools/`.** Anything else (shared logic, traits, serializers) goes in `src/support/` — never in `tools/`, or discovery picks it up.
- **Tools register via `ToolRegistry::collectCoreTools()`** (`RegisterToolsEvent`). Each tool method carries `#[McpTool]` + `#[McpToolMeta(category:, dangerous:)]`.
- **Optional-plugin tools are conditional.** `CommerceTools`, `NeoSchemaTools`, `NeoContentTools`, `NeoScaffoldTools`, `FreeformTools` implement `ConditionalToolProvider::isAvailable()` — `class_exists()` FIRST (so the class loads safely when the plugin is absent), then the plugin-enabled check. Registered only when available.
- **Never hard-reference optional plugin classes** (`benf\neo\*`, `Solspace\Freeform\*`, `craft\commerce\*`). Use lazy FQCN resolution / duck-typed access (`method_exists`, getters). Each has a phpstan ignore pattern in `phpstan.neon`.
- **The MCP server (`bin/mcp-server`) is a long-running process.** Code changes need a **SIGHUP restart** (`SignalHandler` re-execs). The `reload_mcp` tool only detects newly *installed plugins*, not code edits.

## Tests

Two patterns (both boot-free — no Craft/plugin required): **structural** reflection tests for tool classes (`#[McpTool]` present, `dangerous`/category, signature, tool count, unavailable-without-plugin), and **pure-logic** tests for `support/` classes using anonymous `#[AllowDynamicProperties]` class stubs. Don't add framework/fixture-heavy tests unless the logic needs Craft booted.

## Consumption / dev loop

Consumed by the mbd site as a Composer **path repo with a symlink** (`mbd/vendor/2rm/craft-mcp` → this dir), so edits here are live in mbd instantly — no publish. The `craft-mcp` MCP server in the current session connects to mbd's Craft install; after editing plugin code, SIGHUP-restart it before the changes take effect live.

## Project memory

Project memory lives in `docs/wiki/`, which holds what is true about this project. User-facing product docs are in `docs/*.md` / `docs/tools/` and are not project memory.

### Orientation read — every session, no exceptions

Before your first substantive action:

1. **Enumerate.** Run this before reading anything:
   `find docs/wiki -name '*.md' -not -path '*/raw/*'`
   This is the map. It is generated from disk and cannot drift. Nothing you read later replaces it. Never write it to a file — `docs/wiki/` is curated, never compiled.
2. Read `docs/wiki/index.md` — the catalog, with what each page is for.
3. Run `ls docs/wiki/log/ | sort -r | head -10`, then Read the newest 2-3 entries — what recent sessions did.

This fires for every session. Debugging, a one-line fix, code, content, strategy. No topic exempts you. If you are about to conclude "this session isn't really about project content," stop — that judgment is the documented failure mode.

### Authority — agent memory is a routing aid, never project authority

For any project-specific fact, decision, constraint, status, prior conclusion, or commitment:

- **Search the wiki before answering or acting.** Do not answer from session memory.
- **Prefer repository evidence over remembered context.** If they disagree, the repository wins and the memory is what's wrong.
- **Name the wiki page you used.** An answer with no cited page is an inference, not a fact.
- **Check freshness and supersedence** before treating a page as current.
- **If no authoritative answer exists, say so explicitly** and label any inference as inference.

**Delegation.** Never brief a subagent on documented areas from memory. Run the lookup, pass the exact paths. Recall is lossy and every spawned agent multiplies the loss.

### Write

- Session end: `/wrap` digests to the wiki. Do not hand-roll the digest.
- Before writing anything into `docs/wiki/`, read `docs/wiki/WIKI-CLAUDE.md` (this wiki's filing rules) and `docs/wiki/schema.md` (required frontmatter). They are filing rules, not orientation.
- `docs/wiki/raw/` is immutable. Never edit or delete anything under it.
- A recorded conclusion you disprove is superseded in place, never deleted and never left standing. A stale conclusion is worse than a missing one because it will be trusted.
