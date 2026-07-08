<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use benf\neo\Field as NeoField;
use benf\neo\Plugin as Neo;
use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\Mcp;
use twoRivers\craft\Mcp\support\NeoSerializer;
use twoRivers\craft\Mcp\support\SafeExecution;

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
     * Serialize a block type, checking for a matching body_blocks template.
     *
     * @return array<string, mixed>
     */
    private function serializeBlockType(object $blockType): array {
        $handle = (string) ($blockType->handle ?? '');
        $templatePath = "body_blocks/{$handle}.twig";
        $absolutePath = Craft::$app->getPath()->getSiteTemplatesPath()
            . DIRECTORY_SEPARATOR . 'body_blocks'
            . DIRECTORY_SEPARATOR . $handle . '.twig';

        return NeoSerializer::blockType($blockType, is_file($absolutePath), $templatePath);
    }

    /**
     * Resolve the Neo builder field from an explicit handle, an entry, or the
     * builderFieldHandle plugin setting (default 'contentBuilder').
     *
     * @throws ToolCallException
     */
    private function resolveBuilderField(?int $entryId, ?string $fieldHandle): NeoField {
        $handle = $fieldHandle ?? Mcp::settings()->builderFieldHandle;

        if ($entryId !== null) {
            return $this->resolveFieldFromEntry($entryId, $handle, $fieldHandle !== null);
        }

        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if ($field === null) {
            throw new ToolCallException(
                "Field '{$handle}' not found. Pass fieldHandle explicitly or configure the builderFieldHandle plugin setting.",
            );
        }

        if (!$field instanceof NeoField) {
            throw new ToolCallException(
                "Field '{$handle}' is not a Neo field (" . $field::class . ').',
            );
        }

        return $field;
    }

    /**
     * Resolve the Neo field from an entry's field layout.
     *
     * Prefers the field matching $handle; when no explicit handle was given
     * and the layout has exactly one Neo field, that field is used.
     *
     * @throws ToolCallException
     */
    private function resolveFieldFromEntry(int $entryId, string $handle, bool $explicitHandle): NeoField {
        $entry = Craft::$app->getElements()->getElementById($entryId);

        if ($entry === null) {
            throw new ToolCallException("Element with ID {$entryId} not found");
        }

        $neoFields = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof NeoField) {
                continue;
            }

            if ($field->handle === $handle) {
                return $field;
            }

            $neoFields[] = $field;
        }

        if ($explicitHandle) {
            throw new ToolCallException("Entry {$entryId} has no Neo field with handle '{$handle}'");
        }

        if (count($neoFields) === 1) {
            return $neoFields[0];
        }

        if ($neoFields === []) {
            throw new ToolCallException("Entry {$entryId} has no Neo fields in its field layout");
        }

        $handles = implode(', ', array_map(
            static fn (NeoField $field): string => (string) $field->handle,
            $neoFields,
        ));

        throw new ToolCallException(
            "Entry {$entryId} has multiple Neo fields ({$handles}); pass fieldHandle to choose one",
        );
    }

    /**
     * Assert Neo is available, throw exception if not.
     *
     * @throws ToolCallException
     */
    private function assertNeoAvailable(): void {
        if (!self::isAvailable()) {
            throw new ToolCallException('The Neo plugin (benf/craft-neo) is not installed or not enabled');
        }
    }
}
