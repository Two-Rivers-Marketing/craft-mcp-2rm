<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use Craft;
use craft\base\ElementInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\NeoBlockPayload;
use twoRivers\craft\Mcp\support\NeoSerializer;
use twoRivers\craft\Mcp\support\ResolvesNeoBuilderField;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Neo content-builder write tools for Craft CMS.
 *
 * Only registered if the Neo plugin (benf/craft-neo) is installed. Writes
 * always target the entry's CANONICAL element (Craft 5 keeps canonical +
 * derivative elements; writing to a derivative leaves orphaned drafts) and
 * go through Craft's element service, never raw SQL.
 *
 * @author 2RM
 */
class NeoContentTools implements ConditionalToolProvider {
    use ResolvesNeoBuilderField;

    /**
     * Check if the Neo plugin is available.
     */
    public static function isAvailable(): bool {
        return NeoSchemaTools::isAvailable();
    }

    /**
     * Create a single flat Neo block appended at the end of an entry's content builder.
     */
    #[McpTool(
        name: 'create_neo_block',
        description: 'Create a single Neo block appended at the end of an entry\'s content builder field. Targets the entry\'s canonical element so the change appears live. blockType is the block type handle (see describe_content_builder); fields is a JSON object of fieldHandle => value pairs for that block type. Pass dryRun: true to preview a structured before/after diff without saving anything. The builder field resolves from fieldHandle, the builderFieldHandle plugin setting, or the entry\'s sole Neo field.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createNeoBlock(
        int $entryId,
        string $blockType,
        ?string $fieldHandle = null,
        ?string $fields = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($entryId, $blockType, $fieldHandle, $fields, $dryRun): array {
            $this->assertNeoAvailable();

            $owner = $this->resolveCanonicalOwner($entryId);
            $field = $this->resolveBuilderField($entryId, $fieldHandle);
            $blockTypeModel = $this->resolveBlockType($field, $blockType);

            $fieldValues = NeoBlockPayload::decode($fields);
            NeoBlockPayload::assertKnownHandles(
                $fieldValues,
                $this->allowedFieldHandles($blockTypeModel),
                $blockType,
            );

            $existing = $this->existingBlocks($owner, (string) $field->handle);
            $summaries = array_map(NeoBlockPayload::summarizeBlock(...), $existing);

            $appended = [
                'id' => null,
                'type' => $blockType,
                'level' => 1,
                'sortOrder' => count($existing) + 1,
                'fields' => $fieldValues,
            ];

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'entryId' => $owner->id,
                    'fieldHandle' => $field->handle,
                    'diff' => NeoBlockPayload::diff($summaries, $appended),
                ]);
            }

            $block = $this->saveBlock($owner, $field, $blockTypeModel, $fieldValues, count($existing) + 1);
            $this->integrateBlockIntoOwner($owner, $field, $existing, $block);
            $appended['id'] = $block->id;

            return Response::success([
                'entryId' => $owner->id,
                'fieldHandle' => $field->handle,
                'block' => $appended,
                'diff' => NeoBlockPayload::diff($summaries, $appended),
            ]);
        });
    }

    /**
     * Resolve the entry and return its canonical element.
     *
     * Craft 5 keeps canonical + derivative (draft/revision) elements; writes
     * must target the canonical element so edits appear live without
     * orphaned drafts.
     *
     * @throws ToolCallException
     */
    private function resolveCanonicalOwner(int $entryId): ElementInterface {
        $entry = Craft::$app->getElements()->getElementById($entryId);

        if ($entry === null) {
            throw new ToolCallException("Element with ID {$entryId} not found");
        }

        return $entry->getCanonical();
    }

    /**
     * Resolve a block type by handle on the given Neo field.
     *
     * @throws ToolCallException
     */
    private function resolveBlockType(NeoField $field, string $handle): object {
        $blockTypes = $field->getBlockTypes();

        foreach ($blockTypes as $blockTypeModel) {
            if ($blockTypeModel->handle === $handle) {
                return $blockTypeModel;
            }
        }

        $available = implode(', ', array_map(
            static fn ($blockTypeModel) => $blockTypeModel->handle,
            $blockTypes,
        ));

        throw new ToolCallException(
            "Block type '{$handle}' not found on field '{$field->handle}'. Available block types: {$available}",
        );
    }

    /**
     * Collect the custom field handles available on a block type's layout.
     *
     * @return array<int, string>
     */
    private function allowedFieldHandles(object $blockTypeModel): array {
        $layout = method_exists($blockTypeModel, 'getFieldLayout')
            ? $blockTypeModel->getFieldLayout()
            : null;

        $handles = [];
        foreach (NeoSerializer::fieldLayoutFields(is_object($layout) ? $layout : null) as $fieldInfo) {
            if (!is_string($fieldInfo['handle'] ?? null)) {
                continue;
            }

            $handles[] = $fieldInfo['handle'];
        }

        return $handles;
    }

    /**
     * Fetch the owner's existing Neo blocks (all statuses, all levels).
     *
     * @return array<int, object>
     */
    private function existingBlocks(ElementInterface $owner, string $handle): array {
        $value = $owner->getFieldValue($handle);

        if (is_array($value)) {
            return $value;
        }

        if (!is_object($value)) {
            return [];
        }

        if (method_exists($value, 'status')) {
            $value = $value->status(null);
        }

        if (method_exists($value, 'all')) {
            return $value->all();
        }

        return [];
    }

    /**
     * Create and save the new Neo block via Craft's element service.
     *
     * @param array<string, mixed> $fieldValues
     * @throws ToolCallException
     */
    private function saveBlock(
        ElementInterface $owner,
        NeoField $field,
        object $blockTypeModel,
        array $fieldValues,
        int $sortOrder,
    ): Block {
        $block = new Block();
        $block->fieldId = $field->id;
        $block->typeId = $blockTypeModel->id;
        $block->ownerId = $owner->id;
        $block->siteId = $owner->siteId;
        $block->level = 1;
        $block->sortOrder = $sortOrder;
        $block->enabled = true;

        // Craft 5 nested elements track a primary owner separately
        if ($block->canSetProperty('primaryOwnerId')) {
            $block->primaryOwnerId = $owner->id;
        }

        if (method_exists($block, 'setOwner')) {
            $block->setOwner($owner);
        }

        if ($fieldValues !== []) {
            $block->setFieldValues($fieldValues);
        }

        if (!Craft::$app->getElements()->saveElement($block)) {
            throw new ToolCallException(
                'Failed to save Neo block: ' . json_encode($block->getErrors()),
            );
        }

        return $block;
    }

    /**
     * Resave the owner with the new block appended so Neo integrates it into
     * the field's block structure (mirrors Neo's Field::saveValue flow).
     *
     * @param array<int, object> $existing
     * @throws ToolCallException
     */
    private function integrateBlockIntoOwner(
        ElementInterface $owner,
        NeoField $field,
        array $existing,
        Block $block,
    ): void {
        $owner->setFieldValue((string) $field->handle, [...$existing, $block]);

        if (!Craft::$app->getElements()->saveElement($owner)) {
            throw new ToolCallException(
                "Neo block {$block->id} was saved but resaving the owner entry failed: "
                . json_encode($owner->getErrors()),
            );
        }
    }
}
