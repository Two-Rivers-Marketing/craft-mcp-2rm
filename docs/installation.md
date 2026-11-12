# Installation

This guide walks you through installing Craft MCP and connecting it to your preferred AI assistant.

## Requirements

Before installing Craft MCP, ensure your environment meets these requirements:

- **Craft CMS** 5.0 or later
- **PHP** 8.3 or later

The plugin is compatible with all database drivers supported by Craft CMS (MySQL, PostgreSQL).

## Install via Composer

### From the Private Repository

Craft MCP is a private fork, not published to Packagist. Add the VCS repository to your project's `composer.json`, then require it:

```bash
composer config repositories.craft-mcp vcs https://github.com/Two-Rivers-Marketing/craft-mcp-2rm
composer require 2rm/craft-mcp
```

After Composer finishes downloading the package, install the plugin through Craft's CLI:

```bash
php craft plugin/install mcp-2rm
```

### Local Development

If you're contributing to Craft MCP or developing it locally, you can install from a local path:

```bash
# Add the local repository to your composer.json
composer config repositories.craft-mcp path ./plugins/craft-mcp

# Require the package with dev stability
composer require 2rm/craft-mcp:@dev

# Install the plugin in Craft
php craft plugin/install mcp-2rm
```

This approach allows you to make changes to the plugin code and see them reflected immediately without re-publishing to Packagist.

## Verify Installation

To confirm the plugin was installed successfully, run:

```bash
php craft plugin/list
```

You should see `mcp-2rm` in the list with status `Installed`. If the plugin appears but shows a different status, you may need to enable it through the Craft control panel or by running `php craft plugin/enable mcp-2rm`.

## Upgrading from 1.x

2.0.0 renames the plugin handle from `mcp` to `mcp-2rm`. Craft 5.12 uses `mcp` for its native MCP server (the `mcp/*` routes and `config/mcp.php`), so the old handle blocked it. 2.0.0 also accepts `mcp/sdk` 0.8.1 and 0.9.x, which Craft 5.12.0.1 and 5.12.1 require.

On the environment where you make project config changes (usually local):

```bash
composer require 2rm/craft-mcp:^2.0 -W      # add craftcms/cms:^5.12 to upgrade Craft in the same step
mv config/mcp.php config/mcp-2rm.php        # skip if config/mcp.php has no Craft MCP (2RM) settings
php craft plugin/install mcp-2rm
```

Installing `mcp-2rm` removes the old `mcp` install record from the database and project config, unless another package still provides an `mcp` plugin. Commit the project config change; other environments pick it up through `php craft up` / `project-config/apply`.

- **Config file.** Until you rename it, the plugin still reads its settings from `config/mcp.php` and logs a deprecation warning. Rename it anyway: on Craft 5.12+ that file belongs to Craft's own MCP server.
- **Console commands** move with the handle: `php craft mcp-2rm/install` and `php craft mcp-2rm/agents-md`. `php craft mcp/serve` is now Craft's native server.
- **HTTP transport** (devMode only) moves from `/mcp` to `/mcp-2rm`.
- **MCP client configs** need no change: `vendor/2rm/craft-mcp/bin/mcp-server` keeps its path.
- If Craft still lists a missing `mcp` plugin, remove it with `php craft plugin/uninstall mcp --force`.

## Enable the MCP Server

By default, the MCP server is disabled for security. Create a configuration file at `config/mcp-2rm.php` to enable it:

```php
<?php

return [
    'enabled' => true,
];
```

For production environments, you'll want to review the security options in the [Configuration Guide](configuration.md), including IP allowlists and tool restrictions.

## MCP Client Setup

Once the plugin is installed and configured, you'll need to tell your AI assistant how to connect to the MCP server.

### Automatic Setup (Recommended)

The easiest way to configure your MCP clients is using the built-in installation wizard:

```bash
php craft mcp-2rm/install
```

The wizard will:
1. Detect your environment (DDEV or native PHP)
2. Let you select which clients to configure (Claude Code, Cursor, Claude Desktop)
3. Generate the appropriate configuration files automatically

**Options:**

| Option | Alias | Description |
|--------|-------|-------------|
| `--environment` | `-e` | Override detected environment (`ddev` or `native`) |
| `--serverName` | `-s` | Custom server name (default: `craft-cms`) |

**Examples:**

```bash
# Interactive wizard with auto-detection
php craft mcp-2rm/install

# Force DDEV environment
php craft mcp-2rm/install --environment=ddev

# Use a custom server name
php craft mcp-2rm/install --serverName=my-craft-site

# Non-interactive mode (use defaults)
php craft mcp-2rm/install --interactive=0
```

The wizard handles all the details: creating directories, setting correct paths for your environment, and warning you if a server with the same name already exists.

### Point Agents at the Conventions

The plugin ships build conventions as MCP resources (`craft://conventions/*`). To tell agents about them from your project's `AGENTS.md`, run:

```bash
php craft mcp-2rm/agents-md
```

The command writes a short block between `<!-- craft-mcp:conventions:start -->` and `<!-- craft-mcp:conventions:end -->`. Re-running replaces the block in place and never duplicates it. The file is created if it doesn't exist. It's never run automatically, including on plugin install, so it never touches a server.

| Option | Description |
|--------|-------------|
| `--file` | Path to the file to update (default: `AGENTS.md` in the project root, the directory holding `.ddev` or `.git`, or the Craft root if neither is found) |
| `--print` | Write the block, markers included, to stdout and touch no file. Nothing else goes to stdout, so the output can be redirected |
| `--vendor-path` | The vendor directory as seen from the directory holding `AGENTS.md` (default: `vendor`). The block points at `<vendor-path>/2rm/craft-mcp/docs/conventions/`. A trailing slash is trimmed; an empty value is an error |

```bash
# Update the default AGENTS.md
php craft mcp-2rm/agents-md

# Update a different file
php craft mcp-2rm/agents-md --file=/path/to/AGENTS.md

# Print the block instead of writing a file
php craft mcp-2rm/agents-md --print
```

#### Craft in a subfolder

When Craft lives in a subfolder of the repo (for example `app/`) and DDEV mounts only that folder, the command can't reach the repo-root `AGENTS.md`, and the default path in the block (`vendor/...`) is wrong relative to the repo root. Print the block with the vendor path as seen from the repo root, then paste it into `AGENTS.md` (or between the existing markers, replacing the old block):

```bash
ddev craft mcp-2rm/agents-md --print --vendor-path=app/vendor
```

The block then points at `app/vendor/2rm/craft-mcp/docs/conventions/`. `--vendor-path` also applies when writing a file, with or without `--file`.

### Manual Setup

If you prefer to configure manually or need to understand the configuration format, follow the instructions below for your specific client.

#### Claude Code

Claude Code looks for MCP server configurations in a `.mcp.json` file in your project root.

1. Create a new file called `.mcp.json` in your Craft project root
2. Add the server configuration based on your local development environment:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/2rm/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/2rm/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

#### Cursor

Cursor stores MCP configurations in a `.cursor/mcp.json` file within your project.

1. Create the `.cursor` directory in your project root if it doesn't already exist
2. Create a new file called `mcp.json` inside the `.cursor` directory
3. Add the server configuration:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/2rm/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/2rm/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

#### Claude Desktop

Claude Desktop uses a global configuration file rather than a project-specific one. Unlike Claude Code and Cursor, it requires absolute paths for the command and working directory.

1. Open your Claude Desktop configuration file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
2. Add the Craft MCP server to the `mcpServers` object:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/local/bin/ddev",
      "args": ["exec", "php", "vendor/2rm/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```

Replace `/usr/local/bin/ddev` with the actual path to your DDEV installation (find it by running `which ddev` in your terminal), and update the `cwd` value to point to your Craft project directory.
</details>

<details>
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/bin/php",
      "args": ["vendor/2rm/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```

Replace `/usr/bin/php` with the actual path to your PHP installation (find it by running `which php` in your terminal), and update the `cwd` value to point to your Craft project directory.
</details>

## Troubleshooting

### Server not starting

If the MCP server fails to start, check the following:

1. **Plugin is enabled**: Run `php craft plugin/list` and verify the MCP plugin shows as `Installed`
2. **Configuration exists**: Ensure `config/mcp-2rm.php` exists and contains `'enabled' => true`
3. **PHP path is correct**: For Claude Desktop, verify your absolute paths are correct using `which php` or `which ddev`
4. **DDEV is running**: If using DDEV, ensure your containers are up with `ddev start`

### Tools not appearing

If the MCP server connects but tools aren't showing up:

1. **Check for errors**: Look at your Craft logs in `storage/logs/` for any plugin errors
2. **Verify PHP version**: Ensure you're running PHP 8.3 or later

## Next Steps

Now that Craft MCP is installed and connected, you may want to:

1. **[Configure the plugin](configuration.md)** - Review security settings and customize which tools are available
2. **[Explore available tools](tools/README.md)** - Learn what your AI assistant can now do with your Craft installation
