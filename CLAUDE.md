# CLAUDE.md

Craft CMS 5 plugin: an MCP server exposing a Craft install to AI assistants. 2RM fork of `stimmt/craft-mcp`. Package `2rm/craft-mcp`, handle `mcp`, namespace `twoRivers\craft\Mcp` (sub-namespaces lowercase: `tools`, `support`, `services`, `prompts`, `resources`, `models`, `enums`, `events`, `contracts`, `installer`).

PHP 8.3+, `declare(strict_types=1)` in every `src/` file.

## Commands

```bash
composer test                 # Pest (fast, <1s)
composer test:unit            # Unit suite only
vendor/bin/pest --filter=Foo  # single file/group
vendor/bin/pint <files>       # auto-format (lint your OWN touched files)
vendor/bin/phpstan analyse --memory-limit=1G   # NOT `composer analyse` — see gotchas
```

Tests need dev deps: `composer install` (pulls Craft + tooling; `vendor/` is gitignored).

## Gotchas

- **phpstan OOMs at the default 128M.** The `composer analyse` / `composer ci` scripts run plain `phpstan analyse` and die. Always `vendor/bin/phpstan analyse --memory-limit=1G`.
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

## Wiki

Project knowledge (decisions, integration gotchas, QA backlog) lives in `docs/wiki/` — read `docs/wiki/decisions/index.md` and `docs/wiki/plans/qa-feature-backlog.md` before architecture/QA work. User-facing product docs are in `docs/*.md` / `docs/tools/` (separate from the wiki).
