# craft-mcp-2rm
Craft CMS 5 MCP plugin for AI assistants working with Craft sites; package `2rm/craft-mcp` ([composer.json](composer.json)).

## Run it
- In this worker checkout: PHP `^8.4`, then `composer install`; requirements and scripts: [composer.json](composer.json).
- Live use needs a separate Craft site with this worker copy installed beneath its root; `bin/mcp-server` finds `bootstrap.php` and `craft` by walking its parent directories ([bootstrap](bin/mcp-server)).
- From that site's root: `php craft plugin/install mcp`, enable in `config/mcp.php`, then `php vendor/2rm/craft-mcp/bin/mcp-server` ([installation](docs/installation.md), [settings](src/models/Settings.php)).
- No plugin-owned DDEV project is checked in; a consuming site's DDEV runs `ddev exec php vendor/2rm/craft-mcp/bin/mcp-server` ([entry point](bin/mcp-server)).
- Restart your own long-running server after code changes with `kill -HUP <server-pid>`; `reload_mcp` handles plugin discovery, not source reloads ([transport](docs/wiki/architecture/mcp-transport.md)).

## Check it
- `composer test` / `composer test:unit`; focused run: `vendor/bin/pest --filter=Foo` ([scripts](composer.json)).
- `composer lint:test`; format touched files with `vendor/bin/pint <files>`; `composer analyse` supplies PHPStan's `--memory-limit=1G` ([scripts](composer.json)).
- `composer ci`; CI additionally runs `composer refactor:dry`, `composer test:arch`, and `composer validate --strict --no-plugins` ([CI](.github/workflows/ci.yml)).
- No build script in [composer.json](composer.json); tests bootstrap Composer, not a live Craft site, and need no test login ([phpunit.xml](phpunit.xml), [tests/Pest.php](tests/Pest.php)).

## Ship it
- Development PRs target `main`; [CI](.github/workflows/ci.yml) runs on pushes and PRs to `main` and `master`.
- No staging/production branches, deployment workflow, or staging URL are defined in [.github/workflows/](.github/workflows/); installation and promotion belong to each consuming site ([installation](docs/installation.md)).
- Release is manual tag creation/push, then automated GitHub release creation on `v*` tags ([release.yml](.github/workflows/release.yml)); consumer Composer updates and site deployments are manual from this repo's perspective.
- Release workflow still selects PHP 8.3; runtime requires PHP `^8.4` ([release.yml](.github/workflows/release.yml), [composer.json](composer.json)); owner follow-ups: [STATE.md](STATE.md).

## Access
- PHP/Composer checks require no project credential variables ([composer.json](composer.json), [phpunit.xml](phpunit.xml)); no deploy `user@host` is defined in [.github/workflows/](.github/workflows/).
- Site runtime uses the consuming site's bootstrap/environment, including `CRAFT_ENVIRONMENT` ([bin/mcp-server](bin/mcp-server)); get site access and environment configuration from its owner ([installation](docs/installation.md)).
- Optional HTTP access needs `MCP_HTTP_TOKEN` and Craft dev mode ([controller](src/controllers/McpController.php)); configuration examples use `MCP_ENABLED` / `MCP_DANGEROUS_TOOLS` only when wired in `config/mcp.php` ([configuration](docs/configuration.md)).
- GitHub release automation uses Actions-provided `GITHUB_TOKEN` with `contents: write`; repository owners control release permissions ([release.yml](.github/workflows/release.yml)).

## Never
- Do not hard-reference optional plugin classes without availability guards ([ConditionalToolProvider](src/contracts/ConditionalToolProvider.php), [tool registry](src/services/ToolRegistry.php)).
- Do not put shared helpers in `src/tools/`; use [src/support/](src/support/); core tool registration is explicit in [ToolRegistry](src/services/ToolRegistry.php).
- Do not edit/delete `docs/wiki/raw/` or erase superseded conclusions ([wiki rules](docs/wiki/WIKI-CLAUDE.md), [existing contributor guidance](CLAUDE.md)).

## Decisions
- History and decisions: [docs/wiki/decisions/](docs/wiki/decisions/index.md); orientation: [wiki index](docs/wiki/index.md), newest entries in [wiki log](docs/wiki/log/), and [STATE.md](STATE.md).
- Retained contributor guidance: [CLAUDE.md](CLAUDE.md); resolve stale facts against code, including PHP `^8.4` in [composer.json](composer.json) and explicit registration in [ToolRegistry](src/services/ToolRegistry.php).
