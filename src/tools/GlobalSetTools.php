<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use twoRivers\craft\Mcp\support\Serializer;

/**
 * Global set MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class GlobalSetTools {
    /**
     * List all global sets.
     */
    #[McpTool(
        name: 'list_globals',
        description: 'List all global sets in Craft CMS with their field values',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listGlobals(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $globalSets = Craft::$app->getGlobals()->getAllSets();
            $results = array_map($this->serializeGlobalSet(...), $globalSets);

            return Response::list('globals', $results);
        });
    }

    /**
     * Update the field values of a global set.
     */
    #[McpTool(
        name: 'update_global_set',
        description: 'Update the custom field values of a global set, identified by handle. fields is a JSON object of fieldHandle => value pairs. Writes through Craft\'s element service (search index + caches handled). Only listed fields change; omitted fields are untouched.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateGlobalSet(
        string $handle,
        string $fields,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($handle, $fields): array {
            $globalSet = Craft::$app->getGlobals()->getSetByHandle($handle);

            if ($globalSet === null) {
                throw new ToolCallException("Global set with handle '{$handle}' not found");
            }

            $fieldValues = json_decode($fields, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($fieldValues)) {
                throw new ToolCallException('Invalid JSON in fields parameter (expected a JSON object of fieldHandle => value)');
            }

            $globalSet->setFieldValues($fieldValues);

            if (!Craft::$app->getElements()->saveElement($globalSet)) {
                throw new ToolCallException('Failed to save global set: ' . json_encode($globalSet->getErrors()));
            }

            return Response::success(['global' => $this->serializeGlobalSet($globalSet)]);
        });
    }

    /**
     * Serialize a global set to array.
     */
    private function serializeGlobalSet(mixed $globalSet): array {
        $fieldValues = [];
        $fieldLayout = $globalSet->getFieldLayout();

        if ($fieldLayout !== null) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                $fieldValues[$field->handle] = Serializer::serialize(
                    $globalSet->getFieldValue($field->handle),
                );
            }
        }

        return [
            'id' => $globalSet->id,
            'handle' => $globalSet->handle,
            'name' => $globalSet->name,
            'fields' => $fieldValues,
        ];
    }
}
