# Nightshift Progress — 2026-07-15-0353

Append-only notes for the next issue.

## Issue #13: done
`composer analyse` now runs with `--memory-limit=1G` — passes clean (93 files). CLAUDE.md gotcha about avoiding `composer analyse` is now stale.

**Env learning:** worktree `vendor` symlink to parent repo breaks Pest — it derives test namespaces from paths relative to the composer root, yielding invalid `P\worktrees\nightshift\...` namespaces (`error code 255`). Fix: `unlink vendor && composer install` in the worktree.

## Issue #14: done
Rebranded stale `stimmt/craft-mcp` refs to `2rm/craft-mcp` in README.md, docs/README.md, docs/installation.md, CHANGELOG.md, .github/workflows/release.yml, composer.json (`extra.developer`/`extra.documentationUrl`). Dropped packagist badges (private fork isn't published there); install docs now point at the private VCS repo (`Two-Rivers-Marketing/craft-mcp-2rm`) via `composer config repositories.craft-mcp vcs ...`.

Left untouched (out of scope, correctly historical): `composer.json` authors block credits "Max van Essen / Stimmt Digital" as original author with a link to the real upstream repo; `docs/wiki/*` prose describing this project as "the 2RM fork of `stimmt/craft-mcp`" is accurate scene-setting, not stale.

**Env note:** `composer validate --strict` throws an unrelated `Filesystem.php` absolute-path error in this worktree (pre-existing, not caused by this change) — verified composer.json validity via `php -r 'json_decode(...)'` instead. `composer test` (421 tests) passes clean.
