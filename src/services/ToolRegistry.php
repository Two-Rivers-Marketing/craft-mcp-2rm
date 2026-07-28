<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\services;

use Craft;
use twoRivers\craft\Mcp\events\RegisterToolsEvent;
use twoRivers\craft\Mcp\Mcp;
use twoRivers\craft\Mcp\models\ToolDefinition;
use twoRivers\craft\Mcp\tools\AssetTools;
use twoRivers\craft\Mcp\tools\BackupTools;
use twoRivers\craft\Mcp\tools\CategoryTools;
use twoRivers\craft\Mcp\tools\CommerceTools;
use twoRivers\craft\Mcp\tools\CraftTools;
use twoRivers\craft\Mcp\tools\DatabaseTools;
use twoRivers\craft\Mcp\tools\DebugTools;
use twoRivers\craft\Mcp\tools\EntryTools;
use twoRivers\craft\Mcp\tools\FreeformScaffoldTools;
use twoRivers\craft\Mcp\tools\FreeformTools;
use twoRivers\craft\Mcp\tools\GlobalSetTools;
use twoRivers\craft\Mcp\tools\GraphqlTools;
use twoRivers\craft\Mcp\tools\McpTools;
use twoRivers\craft\Mcp\tools\NavigationTools;
use twoRivers\craft\Mcp\tools\NeoContentTools;
use twoRivers\craft\Mcp\tools\NeoScaffoldTools;
use twoRivers\craft\Mcp\tools\NeoSchemaTools;
use twoRivers\craft\Mcp\tools\SiteTools;
use twoRivers\craft\Mcp\tools\SystemTools;
use twoRivers\craft\Mcp\tools\TinkerTools;
use twoRivers\craft\Mcp\tools\UserTools;

/**
 * Registry for MCP tools with isolation and namespacing support.
 *
 * Collects core tools and external tools registered via events,
 * providing safe execution with error isolation.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ToolRegistry {
    /**
     * Core tool classes bundled with this plugin.
     */
    private const array CORE_TOOLS = [
        AssetTools::class,
        BackupTools::class,
        CategoryTools::class,
        CraftTools::class,
        DatabaseTools::class,
        DebugTools::class,
        EntryTools::class,
        GlobalSetTools::class,
        GraphqlTools::class,
        McpTools::class,
        SiteTools::class,
        SystemTools::class,
        TinkerTools::class,
        UserTools::class,
    ];

    /**
     * @var array<string, string[]> Tool classes grouped by source
     */
    private array $tools = [];

    /**
     * @var array<string, ToolDefinition> Tool definitions by name
     */
    private array $definitions = [];

    /**
     * @var array<string, array{path: string, subdirs: string[]}> Discovery paths grouped by source
     */
    private array $discoveryPaths = [];

    /**
     * @var string[] Errors encountered during registration
     */
    private array $errors = [];

    /**
     * @var bool Whether tools have been collected
     */
    private bool $initialized = false;

    /**
     * Get all tool classes for MCP server registration.
     *
     * @return string[]
     */
    public function getToolClasses(): array {
        $this->ensureInitialized();

        $classes = [];
        foreach ($this->tools as $sourceTools) {
            $classes = array_merge($classes, $sourceTools);
        }

        return $classes;
    }

    /**
     * Get tools grouped by source for debugging/info.
     *
     * @return array<string, string[]>
     */
    public function getToolsBySource(): array {
        $this->ensureInitialized();

        return $this->tools;
    }

    /**
     * Get a specific tool definition by name.
     */
    public function getDefinition(string $toolName): ?ToolDefinition {
        $this->ensureInitialized();

        return $this->definitions[$toolName] ?? null;
    }

    /**
     * Get all tool definitions.
     *
     * @return array<string, ToolDefinition>
     */
    public function getDefinitions(): array {
        $this->ensureInitialized();

        return $this->definitions;
    }

    /**
     * Get tool definitions grouped by source.
     *
     * @return array<string, ToolDefinition[]>
     */
    public function getDefinitionsBySource(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->definitions as $definition) {
            $bySource[$definition->source][] = $definition;
        }

        return $bySource;
    }

    /**
     * Get tool definitions grouped by category.
     *
     * @return array<string, ToolDefinition[]>
     */
    public function getDefinitionsByCategory(): array {
        $this->ensureInitialized();

        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category][] = $definition;
        }

        return $byCategory;
    }

    /**
     * Get all dangerous tool names.
     *
     * @return string[]
     */
    public function getDangerousTools(): array {
        $this->ensureInitialized();

        $dangerous = [];
        foreach ($this->definitions as $definition) {
            if ($definition->dangerous) {
                $dangerous[] = $definition->name;
            }
        }

        return $dangerous;
    }

    /**
     * Get tool definitions registered by external plugins (not core).
     *
     * Used by McpServerFactory for manual registration of external tools.
     *
     * @return ToolDefinition[]
     */
    public function getExternalToolDefinitions(): array {
        $this->ensureInitialized();

        $external = [];
        foreach ($this->definitions as $definition) {
            if ($definition->source !== 'core') {
                $external[] = $definition;
            }
        }

        return $external;
    }

    /**
     * Get any errors encountered during tool registration.
     *
     * @return string[]
     */
    public function getErrors(): array {
        $this->ensureInitialized();

        return $this->errors;
    }

    /**
     * Get all discovery paths for MCP SDK.
     *
     * @return array<string, array{path: string, subdirs: string[]}>
     */
    public function getDiscoveryPaths(): array {
        $this->ensureInitialized();

        return $this->discoveryPaths;
    }

    /**
     * Get summary of registered tools for logging.
     *
     * @return array{total: int, by_source: array<string, int>, by_category: array<string, int>, dangerous: int, errors: int}
     */
    public function getSummary(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->tools as $source => $tools) {
            $bySource[$source] = count($tools);
        }

        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category] = ($byCategory[$definition->category] ?? 0) + 1;
        }

        return [
            'total' => count($this->definitions),
            'by_source' => $bySource,
            'by_category' => $byCategory,
            'dangerous' => count($this->getDangerousTools()),
            'errors' => count($this->errors),
        ];
    }

    /**
     * Reset the registry (useful for testing or hot-reload).
     */
    public function reset(): void {
        $this->initialized = false;
        $this->tools = [];
        $this->definitions = [];
        $this->discoveryPaths = [];
        $this->errors = [];
    }

    /**
     * Initialize and collect all tools.
     */
    private function ensureInitialized(): void {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Create event and register core tools first
        $event = new RegisterToolsEvent();
        $this->collectCoreTools($event);

        // Fire event for external plugins
        $plugin = Mcp::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Mcp::EVENT_REGISTER_TOOLS)) {
            $plugin->trigger(Mcp::EVENT_REGISTER_TOOLS, $event);
        }

        // Collect everything from the event
        $this->tools = $event->getTools();
        $this->definitions = $event->getDefinitions();
        $this->discoveryPaths = $event->getDiscoveryPaths();
        $this->errors = $event->getErrors();

        // Log warnings for any errors
        foreach ($this->errors as $error) {
            Craft::warning("MCP tool registration error: {$error}", __METHOD__);
        }

        $summary = $this->getSummary();
        Craft::info(
            sprintf(
                'MCP ToolRegistry initialized: %d tools (%d dangerous) from %s',
                $summary['total'],
                $summary['dangerous'],
                implode(', ', array_map(
                    fn ($source, $count) => "{$source}: {$count}",
                    array_keys($summary['by_source']),
                    $summary['by_source'],
                )),
            ),
            __METHOD__,
        );
    }

    /**
     * Register core tools bundled with this plugin.
     */
    private function collectCoreTools(RegisterToolsEvent $event): void {
        $tools = self::CORE_TOOLS;

        // Add Commerce tools if Commerce is installed
        if (CommerceTools::isAvailable()) {
            $tools[] = CommerceTools::class;
        }

        // Add Neo schema tools if Neo is installed
        if (NeoSchemaTools::isAvailable()) {
            $tools[] = NeoSchemaTools::class;
        }

        // Add Neo content write tools if Neo is installed
        if (NeoContentTools::isAvailable()) {
            $tools[] = NeoContentTools::class;
        }

        // Add Neo scaffolding tools (create_block_type) if Neo is installed
        if (NeoScaffoldTools::isAvailable()) {
            $tools[] = NeoScaffoldTools::class;
        }

        // Add Freeform tools if Freeform is installed
        if (FreeformTools::isAvailable()) {
            $tools[] = FreeformTools::class;
        }

        // Add Freeform form-scaffolding tools (create_form) if Freeform is installed
        if (FreeformScaffoldTools::isAvailable()) {
            $tools[] = FreeformScaffoldTools::class;
        }

        // Add Navigation tools (nav/node CRUD) if verbb/navigation is installed
        if (NavigationTools::isAvailable()) {
            $tools[] = NavigationTools::class;
        }

        // Use addCoreTools which bypasses source validation
        $event->addCoreTools($tools);

        // Core plugin discovery path
        $pluginPath = dirname(__DIR__);
        $event->addDiscoveryPath($pluginPath, ['.', 'tools'], 'core');
    }
}
