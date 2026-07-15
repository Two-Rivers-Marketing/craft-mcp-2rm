# `create_form` v1 — minimal single-page Freeform form creator (implemented)

**Status:** implemented (pending live verification)
**Date:** 2026-07-15
**Issue:** #19
**Supersedes the create_form portion of:** [decisions/deferred/2026-07-14-freeform-write-tools.md](../deferred/2026-07-14-freeform-write-tools.md)

## Decision

Shipped the minimal v1 `create_form` tool scoped in [plans/create-form-tool.md](../../plans/create-form-tool.md):
a single-page Freeform form from a `{label, type, handle?, required?, options?}`
field spec. Field types: `text`, `textarea`, `email`, `dropdown` (+options),
`checkbox`, `number`. Out of scope (takes Freeform defaults): notifications,
integrations/element-connections, spam, multi-page, conditional rules,
submit-button customization.

## Implementation

- **`src/tools/FreeformScaffoldTools.php`** — conditional tool (`isAvailable()` delegates to `FreeformTools::isAvailable()`, class_exists-first), `create_form` method, `#[McpToolMeta(category: CONTENT, dangerous: true)]`, `dryRun` previews form/fields/layout without saving. Mirrors `NeoScaffoldTools::create_block_type` (plan-then-execute).
- **`src/support/FreeformFormPlan.php`** — pure-logic spec decode/validation + field-type keyword→FQCN map + option-config builder.
- All Freeform access via lazily-resolved **string FQCNs** / duck-typed calls (never imports `Solspace\Freeform\*`), so the class loads without the plugin.
- Persists via the CP events `EVENT_CREATE_FORM` + `EVENT_UPSERT_FORM` (see [architecture/freeform-integration.md](../../architecture/freeform-integration.md) for the full API and the two gotchas: user-identity requirement, per-field property defaults).
- Registered in `ToolRegistry::collectCoreTools()` behind `FreeformScaffoldTools::isAvailable()`.

## Tests

Boot-free, mirroring the existing patterns: structural reflection test
(`tests/Unit/Tools/FreeformScaffoldToolsTest.php`) + pure-logic test
(`tests/Unit/Support/FreeformFormPlanTest.php`). 28 new tests. Full suite 449
pass, phpstan clean (`--memory-limit=1G`), pint clean.

The tool code was NOT live-verified — it is not deployed to the running MCP
server (symlinked main-repo code + SIGHUP required). Live verification (create a
form + accept a submission on mbd after SIGHUP) is handed off on issue #19.

## Not relitigated

`update_form`, notification/element-connection config, and multi-page/conditional
logic remain future work (see the plan's "Later" section and the backlog).
