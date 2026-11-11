<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Path;
use craft\web\UrlManager;
use Override;
use twoRivers\craft\Mcp\events\RegisterPromptsEvent;
use twoRivers\craft\Mcp\events\RegisterResourcesEvent;
use twoRivers\craft\Mcp\events\RegisterToolsEvent;
use twoRivers\craft\Mcp\models\Settings;
use twoRivers\craft\Mcp\services\McpServerFactory;
use twoRivers\craft\Mcp\services\PromptRegistry;
use twoRivers\craft\Mcp\services\ResourceRegistry;
use twoRivers\craft\Mcp\services\ToolRegistry;
use yii\base\Event;

/**
 * Craft MCP Plugin
 *
 * Provides MCP (Model Context Protocol) server functionality for Craft CMS.
 * This allows AI assistants like Claude to interact with Craft installations.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Mcp extends BasePlugin {
    /**
     * Plugin handle. Was `mcp` before 2.0.0; Craft 5.12 claimed that name for
     * its native MCP server (`mcp/*` routes, `config/mcp.php`).
     */
    public const HANDLE = 'mcp-2rm';

    /**
     * Pre-2.0.0 handle, still checked for an old config file and a stale install record.
     */
    public const LEGACY_HANDLE = 'mcp';

    /**
     * Event fired to allow plugins to register MCP tools.
     */
    public const EVENT_REGISTER_TOOLS = 'registerTools';

    /**
     * Event fired to allow plugins to register MCP prompts.
     */
    public const EVENT_REGISTER_PROMPTS = 'registerPrompts';

    /**
     * Event fired to allow plugins to register MCP resources.
     */
    public const EVENT_REGISTER_RESOURCES = 'registerResources';

    /**
     * Tools that can modify data or execute code.
     *
     * @deprecated Use getToolRegistry()->getDangerousTools() instead.
     *             This constant is kept for backwards compatibility.
     */
    public const DANGEROUS_TOOLS = [
        'tinker',
        'run_query',
        'create_entry',
        'update_entry',
        'clear_caches',
        'create_backup',
        'execute_graphql',
    ];

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    private static ?Settings $loadedSettings = null;

    private static ?ToolRegistry $toolRegistry = null;

    private static ?PromptRegistry $promptRegistry = null;

    private static ?ResourceRegistry $resourceRegistry = null;

    /**
     * @return array{components: array<string, mixed>}
     */
    #[Override]
    public static function config(): array {
        return [
            'components' => [],
        ];
    }

    #[Override]
    public function init(): void {
        parent::init();

        $this->registerHttpTransportRoute();

        Craft::info('Craft MCP plugin loaded', __METHOD__);
    }

    /**
     * Register the HTTP-transport site route — dev-only convenience so code
     * edits go live without restarting the stdio server. Gated on devMode +
     * the httpTransport setting; the controller additionally enforces a bearer
     * token. Off in production because the endpoint can execute arbitrary PHP.
     */
    private function registerHttpTransportRoute(): void {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return;
        }

        if (!self::settings()->httpTransport) {
            return;
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules[self::HANDLE] = self::HANDLE . '/mcp/handle';
            },
        );
    }

    protected function createSettingsModel(): ?Model {
        return new Settings();
    }

    #[Override]
    protected function afterInstall(): void {
        parent::afterInstall();

        $this->removeLegacyInstall();
    }

    /**
     * Drop the install record left by the pre-2.0.0 `mcp` handle, so Craft
     * stops listing it as a missing plugin. Skipped while project config is
     * being applied (the incoming YAML already carries the removal) and when
     * another package still provides an `mcp` plugin, e.g. upstream
     * stimmt/craft-mcp.
     */
    private function removeLegacyInstall(): void {
        $plugins = Craft::$app->getPlugins();
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        if ($plugins->getComposerPluginInfo(self::LEGACY_HANDLE) !== null) {
            return;
        }

        if (!$plugins->isPluginInstalled(self::LEGACY_HANDLE) && $projectConfig->get('plugins.' . self::LEGACY_HANDLE) === null) {
            return;
        }

        $plugins->uninstallPlugin(self::LEGACY_HANDLE, true);
        Craft::info('Removed the install record of the pre-2.0.0 `mcp` handle.', __METHOD__);
    }

    /**
     * Get the plugin settings with config file overrides applied.
     */
    public static function settings(): Settings {
        if (self::$loadedSettings !== null) {
            return self::$loadedSettings;
        }

        $plugin = self::getInstance();
        if ($plugin === null) {
            return new Settings();
        }

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // Apply config file overrides
        self::$loadedSettings = self::applyConfigOverrides($settings);

        return self::$loadedSettings;
    }

    /**
     * Get the tool registry instance.
     */
    public static function getToolRegistry(): ToolRegistry {
        if (self::$toolRegistry === null) {
            self::$toolRegistry = new ToolRegistry();
        }

        return self::$toolRegistry;
    }

    /**
     * Get the prompt registry instance.
     */
    public static function getPromptRegistry(): PromptRegistry {
        if (self::$promptRegistry === null) {
            self::$promptRegistry = new PromptRegistry();
        }

        return self::$promptRegistry;
    }

    /**
     * Get the resource registry instance.
     */
    public static function getResourceRegistry(): ResourceRegistry {
        if (self::$resourceRegistry === null) {
            self::$resourceRegistry = new ResourceRegistry();
        }

        return self::$resourceRegistry;
    }

    /**
     * Reset the tool registry to force re-initialization.
     *
     * Useful for detecting newly installed plugins without full server restart.
     */
    public static function resetToolRegistry(): void {
        self::$toolRegistry = null;
    }

    /**
     * Reset the prompt registry to force re-initialization.
     */
    public static function resetPromptRegistry(): void {
        self::$promptRegistry = null;
    }

    /**
     * Reset the resource registry to force re-initialization.
     */
    public static function resetResourceRegistry(): void {
        self::$resourceRegistry = null;
    }

    /**
     * Reset all registries to force re-initialization.
     *
     * Useful for detecting newly installed plugins without full server restart.
     */
    public static function resetRegistries(): void {
        self::$toolRegistry = null;
        self::$promptRegistry = null;
        self::$resourceRegistry = null;
    }

    /**
     * Create a new MCP server factory instance.
     *
     * The factory handles server building with proper SDK patterns,
     * including discovery, container, and logging configuration.
     */
    public function getServerFactory(): McpServerFactory {
        return new McpServerFactory();
    }

    /**
     * Apply config/mcp-2rm.php overrides to settings, falling back to the
     * pre-2.0.0 config/mcp.php when only that one exists.
     */
    private static function applyConfigOverrides(Settings $settings): Settings {
        /** @var Path $pathService */
        $pathService = Craft::$app->getPath();
        $configDir = $pathService->getConfigPath();
        $configPath = $configDir . '/' . self::HANDLE . '.php';

        if (!file_exists($configPath)) {
            return self::applyLegacyConfigOverrides($settings, $configDir . '/' . self::LEGACY_HANDLE . '.php');
        }

        $config = require $configPath;
        if (!is_array($config)) {
            return $settings;
        }

        return self::applyConfigArray($settings, $config);
    }

    /**
     * Honour a pre-2.0.0 config/mcp.php so upgrading doesn't silently drop a
     * site's restrictions (disabled tools, dangerous tools off). Only used when
     * the file sets at least one of this plugin's settings: on Craft 5.12+ the
     * file belongs to Craft's own MCP server.
     */
    private static function applyLegacyConfigOverrides(Settings $settings, string $legacyPath): Settings {
        if (!file_exists($legacyPath)) {
            return self::applyProductionDefaults($settings);
        }

        $config = require $legacyPath;
        if (!is_array($config)) {
            return self::applyProductionDefaults($settings);
        }

        $ownKeys = array_filter(array_keys($config), static fn (int|string $key): bool => is_string($key) && property_exists($settings, $key));
        if ($ownKeys === []) {
            return self::applyProductionDefaults($settings);
        }

        Craft::$app->getDeprecator()->log(
            'mcp-2rm:config/mcp.php',
            'Craft MCP (2RM) settings in `config/mcp.php` are deprecated. Rename the file to `config/' . self::HANDLE . '.php`: Craft 5.12+ reads `config/mcp.php` for its own MCP server.',
        );

        return self::applyConfigArray($settings, $config);
    }

    /**
     * @param array<mixed> $config
     */
    private static function applyConfigArray(Settings $settings, array $config): Settings {
        foreach ($config as $key => $value) {
            if (property_exists($settings, $key)) {
                $settings->$key = $value;
            }
        }

        // Production keeps dangerous tools off unless the config file turns
        // them on by name. Enabling the server alone must not enable them.
        if (self::isProduction() && !array_key_exists('enableDangerousTools', $config)) {
            $settings->enableDangerousTools = false;
        }

        return $settings;
    }

    /**
     * Apply safe defaults when no config file exists.
     */
    private static function applyProductionDefaults(Settings $settings): Settings {
        if (self::isProduction()) {
            $settings->enabled = false;
            $settings->enableDangerousTools = false;
        }

        return $settings;
    }

    private static function isProduction(): bool {
        return (Craft::$app->env ?? getenv('CRAFT_ENVIRONMENT') ?: 'production') === 'production';
    }

    /**
     * Check if MCP is enabled.
     */
    public static function isEnabled(): bool {
        return self::settings()->enabled;
    }

    /**
     * Check if a specific tool is enabled.
     */
    public static function isToolEnabled(string $toolName): bool {
        $settings = self::settings();

        if (!$settings->enabled) {
            return false;
        }

        if (in_array($toolName, $settings->disabledTools, true)) {
            return false;
        }

        // Get tool definition from registry
        $definition = self::getToolRegistry()->getDefinition($toolName);

        // Check method-level condition
        if ($definition !== null && !$definition->isConditionMet()) {
            return false;
        }

        // Check if tool is dangerous
        $isDangerous = $definition !== null
            ? $definition->dangerous
            : in_array($toolName, self::DANGEROUS_TOOLS, true); // Fallback for backwards compat

        return !$isDangerous || $settings->enableDangerousTools;
    }

    /**
     * Check if a specific prompt is enabled.
     */
    public static function isPromptEnabled(string $promptName): bool {
        $settings = self::settings();

        if (!$settings->enabled) {
            return false;
        }

        if (in_array($promptName, $settings->disabledPrompts, true)) {
            return false;
        }

        // Get prompt definition from registry
        $definition = self::getPromptRegistry()->getDefinition($promptName);

        // Check method-level condition
        if ($definition !== null && !$definition->isConditionMet()) {
            return false;
        }

        return true;
    }

    /**
     * Check if a specific resource is enabled.
     */
    public static function isResourceEnabled(string $resourceUri): bool {
        $settings = self::settings();

        if (!$settings->enabled) {
            return false;
        }

        if (in_array($resourceUri, $settings->disabledResources, true)) {
            return false;
        }

        // Get resource definition from registry
        $definition = self::getResourceRegistry()->getDefinition($resourceUri);

        // Check method-level condition
        if ($definition !== null && !$definition->isConditionMet()) {
            return false;
        }

        return true;
    }

    /**
     * Get all dangerous tool names.
     *
     * @return string[]
     */
    public static function getDangerousTools(): array {
        return self::getToolRegistry()->getDangerousTools();
    }

    /**
     * Collect tool classes from other plugins via event.
     *
     * @deprecated Use getToolRegistry() instead. The registry now handles all tool collection.
     * @return RegisterToolsEvent The event containing registered tools and any errors
     */
    public static function collectExternalTools(): RegisterToolsEvent {
        $event = new RegisterToolsEvent();

        $plugin = self::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(self::EVENT_REGISTER_TOOLS)) {
            $plugin->trigger(self::EVENT_REGISTER_TOOLS, $event);
        }

        // Log any validation errors
        foreach ($event->getErrors() as $error) {
            Craft::warning("MCP tool registration error: {$error}", __METHOD__);
        }

        return $event;
    }

    /**
     * Collect prompt classes from other plugins via event.
     *
     * @deprecated Use getPromptRegistry() instead. The registry now handles all prompt collection.
     * @return RegisterPromptsEvent The event containing registered prompts and any errors
     */
    public static function collectExternalPrompts(): RegisterPromptsEvent {
        $event = new RegisterPromptsEvent();

        $plugin = self::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(self::EVENT_REGISTER_PROMPTS)) {
            $plugin->trigger(self::EVENT_REGISTER_PROMPTS, $event);
        }

        // Log any validation errors
        foreach ($event->getErrors() as $error) {
            Craft::warning("MCP prompt registration error: {$error}", __METHOD__);
        }

        return $event;
    }

    /**
     * Collect resource classes from other plugins via event.
     *
     * @deprecated Use getResourceRegistry() instead. The registry now handles all resource collection.
     * @return RegisterResourcesEvent The event containing registered resources and any errors
     */
    public static function collectExternalResources(): RegisterResourcesEvent {
        $event = new RegisterResourcesEvent();

        $plugin = self::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(self::EVENT_REGISTER_RESOURCES)) {
            $plugin->trigger(self::EVENT_REGISTER_RESOURCES, $event);
        }

        // Log any validation errors
        foreach ($event->getErrors() as $error) {
            Craft::warning("MCP resource registration error: {$error}", __METHOD__);
        }

        return $event;
    }
}
