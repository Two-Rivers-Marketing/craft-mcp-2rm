<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use benf\neo\Plugin as Neo;
use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\NeoSerializer;
use twoRivers\craft\Mcp\support\ResolvesNeoBuilderField;
use twoRivers\craft\Mcp\support\SafeExecution;
use yii\base\Event;

/**
 * Neo content-builder schema tools for Craft CMS.
 *
 * These tools are only registered if the Neo plugin (benf/craft-neo) is installed.
 * They give AI assistants one-call awareness of a Neo content builder field:
 * every block type, its fields (with valid option values), nesting rules, and
 * whether a matching body_blocks template exists.
 *
 * @author 2RM
 */
class NeoSchemaTools implements ConditionalToolProvider {
    use ResolvesNeoBuilderField;

    /**
     * Check if the Neo plugin is available.
     *
     * Uses cached plugin state first (fast), falls back to project config
     * to detect plugins installed after MCP server start.
     */
    public static function isAvailable(): bool {
        if (!class_exists(Neo::class)) {
            return false;
        }

        $plugins = Craft::$app->getPlugins();

        // Fast path: plugin was loaded at Craft bootstrap
        if ($plugins->isPluginEnabled('neo')) {
            return true;
        }

        // Check project config (reads from YAML, detects post-boot installs)
        $config = Craft::$app->getProjectConfig()->get('plugins.neo');
        $enabledInConfig = $config !== null && ($config['enabled'] ?? false) === true;

        if (!$enabledInConfig) {
            return false;
        }

        // Plugin is enabled in config but not loaded - try reloading plugins
        $plugins->loadPlugins();

        return $plugins->isPluginEnabled('neo');
    }

    /**
     * Describe the full content builder model of a Neo field.
     */
    #[McpTool(
        name: 'describe_content_builder',
        description: 'Describe the full content builder model of a Neo field: every block type with its fields (including valid option values), nesting rules, and whether a matching body_blocks template exists. Defaults to the configured builder field (builderFieldHandle setting); pass entryId to resolve the builder field from a specific entry, or fieldHandle to target another Neo field. Call this before any content-builder work.',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function describeContentBuilder(
        ?int $entryId = null,
        ?string $fieldHandle = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($entryId, $fieldHandle): array {
            $this->assertNeoAvailable();

            $field = $this->resolveBuilderField($entryId, $fieldHandle);

            $blockTypes = array_map(
                $this->serializeBlockType(...),
                $field->getBlockTypes(),
            );

            return [
                'success' => true,
                'field' => [
                    'id' => $field->id,
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'minBlocks' => $field->minBlocks ?? null,
                    'maxBlocks' => $field->maxBlocks ?? null,
                    'minTopLevelBlocks' => $field->minTopLevelBlocks ?? null,
                    'maxTopLevelBlocks' => $field->maxTopLevelBlocks ?? null,
                ],
                'blockTypeCount' => count($blockTypes),
                'blockTypes' => $blockTypes,
            ];
        });
    }

    /**
     * Get one block type of the content builder in full depth.
     */
    #[McpTool(
        name: 'get_block_type',
        description: 'Get one Neo block type in full depth: fields (with valid option values), nesting rules, and body_blocks template existence. Looks up the block type by handle on the configured builder field (builderFieldHandle setting), or on another Neo field via fieldHandle.',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function getBlockType(
        string $handle,
        ?string $fieldHandle = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($handle, $fieldHandle): array {
            $this->assertNeoAvailable();

            $field = $this->resolveBuilderField(null, $fieldHandle);
            $blockTypes = $field->getBlockTypes();

            foreach ($blockTypes as $blockType) {
                if ($blockType->handle !== $handle) {
                    continue;
                }

                return [
                    'success' => true,
                    'fieldHandle' => $field->handle,
                    'blockType' => $this->serializeBlockType($blockType),
                ];
            }

            $available = implode(', ', array_map(
                static fn ($blockType) => $blockType->handle,
                $blockTypes,
            ));

            throw new ToolCallException(
                "Block type '{$handle}' not found on field '{$field->handle}'. Available block types: {$available}",
            );
        });
    }

    /**
     * Serialize a block type, resolving its template across all registered
     * template roots (not just the site templates path).
     *
     * @return array<string, mixed>
     */
    private function serializeBlockType(object $blockType): array {
        $handle = (string) ($blockType->handle ?? '');
        $topLevel = (bool) ($blockType->topLevel ?? true);

        $candidates = $topLevel
            ? ["body_blocks/{$handle}.twig"]
            : ["_includes/columnItems/{$handle}.twig", "body_blocks/{$handle}.twig"];

        /** @var View $view */
        $view = Craft::$app->getView();
        $siteRoot = Craft::$app->getPath()->getSiteTemplatesPath();
        $resolvedPath = null;
        $resolvedAbsolute = null;
        $servedBy = null;

        foreach ($candidates as $candidate) {
            $absolute = $view->resolveTemplate($candidate, View::TEMPLATE_MODE_SITE);
            if ($absolute === false) {
                continue;
            }
            $resolvedPath = $candidate;
            $resolvedAbsolute = $absolute;
            $servedBy = str_starts_with($absolute, $siteRoot) ? 'project' : 'plugin';
            break;
        }

        // ponytail: Craft's resolveTemplate only finds plugin-root templates
        // when the path is prefixed with the root key (e.g. "site-toolkit/body_blocks/foo.twig").
        // Fire the event to discover root keys, build prefixed candidates, then try each.
        if ($resolvedAbsolute === null) {
            $event = new RegisterTemplateRootsEvent();
            Event::trigger(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, $event);

            $prefixed = $this->prefixedTemplateCandidates($candidates, array_keys($event->roots));

            foreach ($prefixed as [$rootKey, $candidate, $prefixedPath]) {
                $absolute = $view->resolveTemplate($prefixedPath, View::TEMPLATE_MODE_SITE);
                if ($absolute === false) {
                    continue;
                }
                $resolvedPath = $candidate;
                $resolvedAbsolute = $absolute;
                $servedBy = $rootKey;
                break;
            }
        }

        return NeoSerializer::blockType(
            $blockType,
            $resolvedAbsolute !== null,
            $resolvedPath ?? $candidates[0],
            $servedBy,
        );
    }

    /**
     * Build a flat list of [rootKey, candidate, prefixedPath] tuples for
     * resolveTemplate, avoiding nested loops (phpstan: NoNestedLoops).
     *
     * @param list<string> $candidates
     * @param list<string> $rootKeys
     * @return list<array{string, string, string}>
     */
    private function prefixedTemplateCandidates(array $candidates, array $rootKeys): array {
        $result = [];
        foreach ($rootKeys as $rootKey) {
            $result = [
                ...$result,
                ...array_map(
                    static fn (string $c): array => [$rootKey, $c, "{$rootKey}/{$c}"],
                    $candidates,
                ),
            ];
        }

        return $result;
    }
}
