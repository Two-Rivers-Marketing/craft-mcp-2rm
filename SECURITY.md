# Security

`2rm/craft-mcp` exposes a Craft install to an MCP client. Several of its tools write content,
run queries, or execute code. This file states the trust boundary, the accepted risks, and how to
lock the plugin down.

## Trust boundary

**Anyone who can reach the MCP server can act as the Craft web/console user.** That is the boundary —
not any individual tool. 31 of the plugin's tools are marked `dangerous: true` (every entry, user,
Neo, navigation, Freeform and backup write path), and `tinker` executes PHP directly.

Treat MCP access as equivalent to shell access on the box. Do not expose it to untrusted clients.

## `tinker` and `eval()` — accepted risk

`tinker` is a PHP REPL. It calls `eval()` on the code it is given
([`src/tools/TinkerTools.php`](src/tools/TinkerTools.php)). Static analysis flags this as an unsafe
`eval` sink, correctly: **the sink is the feature.** An allowlist of permitted expressions would not
be a REPL, and the tool exists precisely because it is the escape hatch for everything the dedicated
tools do not yet cover.

What limits it:

- **devMode gate.** With Craft `devMode` off, `tinker` refuses before reaching `eval()`. This is the
  same boundary the HTTP transport and `reload_mcp` already use.
- **Blocklist.** A regex blocklist rejects shell execution, file writes, nested `eval`, and unbounded
  output-buffer teardown loops. It is **not** a sandbox and is trivially bypassable
  (`call_user_func`, variable functions). It is a foot-gun guard, not a security control — the
  in-file comments say so, and this file confirms it.
- **`dangerous: true`.** `tinker` can be dropped entirely, per install (see below).

The residual risk is deliberate: in a devMode environment, an MCP client can run arbitrary PHP.
If that is not acceptable for a given install, disable the tool.

`run_query` (raw SQL), `execute_graphql` (mutations), and `render_template` (Twig) are lesser variants of the
same shape and are all marked dangerous.

## Locking it down

In `config/mcp.php`:

```php
return [
    // Drop just the REPL, keep every other tool.
    'disabledTools' => ['tinker'],

    // Or drop all 31 dangerous tools — read-only server. Breaks every write tool.
    'enableDangerousTools' => false,

    // Off by default. Only ever registers when devMode is on AND MCP_HTTP_TOKEN is set.
    'httpTransport' => false,
];
```

Notes:

- `enableDangerousTools` defaults to `true`, because the plugin's purpose is content authoring. Set
  it to `false` for any install where the MCP client should only read.
- The HTTP transport requires a bearer token in `MCP_HTTP_TOKEN`, compared with `hash_equals`
  ([`src/controllers/McpController.php`](src/controllers/McpController.php)). It is a dev convenience;
  do not enable it in production.
- The stdio transport is a local child process of the MCP client and inherits that client's trust.

## Reporting

Open a GitHub issue, or contact the maintainers directly for anything exploitable in a deployed
install.
