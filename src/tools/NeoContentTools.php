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
use Throwable;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\NeoBlockPayload;
use twoRivers\craft\Mcp\support\NeoBlockTree;
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
     * Create a Neo block — optionally a whole nested tree — at a chosen
     * position within an entry's content builder.
     */
    #[McpTool(
        name: 'create_neo_block',
        description: 'Create one or a whole nested tree of Neo blocks in an entry\'s content builder field in a single call. Targets the entry\'s canonical element so the change appears live. blockType is the root block type handle (see describe_content_builder); fields is a JSON object of fieldHandle => value pairs. children is an optional JSON array of nested block payloads, each with the same shape {blockType, fields, children} (e.g. a multiColumn with two columnItem children). position optionally places the new block among its siblings: an integer index ("0", "3"), or "before:<blockId>" / "after:<blockId>" referencing an existing sibling; default appends at the end. parentBlockId optionally nests the new block inside an existing block (position then applies within that parent\'s children). Pass dryRun: true to preview a flattened, leveled before/after diff without saving.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createNeoBlock(
        int $entryId,
        string $blockType,
        ?string $fieldHandle = null,
        ?string $fields = null,
        ?string $children = null,
        ?string $position = null,
        ?int $parentBlockId = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use (
            $entryId,
            $blockType,
            $fieldHandle,
            $fields,
            $children,
            $position,
            $parentBlockId,
            $dryRun,
        ): array {
            $this->assertNeoAvailable();

            $owner = $this->resolveCanonicalOwner($entryId);
            $field = $this->resolveBuilderField($entryId, $fieldHandle);

            $tree = NeoBlockTree::normalizeTree(
                $blockType,
                NeoBlockPayload::decode($fields),
                NeoBlockTree::decodeChildren($children),
            );
            $this->validateTree($field, $tree);

            $existing = $this->existingBlocks($owner, (string) $field->handle);
            $summaries = array_map(NeoBlockPayload::summarizeBlock(...), $existing);

            $scope = $this->resolveScope($summaries, $existing, $field, $parentBlockId, $blockType);
            $insertionIndex = NeoBlockTree::resolveInsertionIndex(
                $summaries,
                $position,
                $scope['start'],
                $scope['end'],
                $scope['level'],
            );
            $flatNew = NeoBlockTree::flatten($tree, $scope['level']);

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'entryId' => $owner->id,
                    'fieldHandle' => $field->handle,
                    'parentBlockId' => $parentBlockId,
                    'diff' => NeoBlockTree::diff($summaries, $insertionIndex, $flatNew),
                ]);
            }

            $newBlocks = $this->buildTreeBlocks($owner, $field, $flatNew);
            $this->persistTree($owner, $field, $existing, $newBlocks, $insertionIndex);

            return Response::success([
                'entryId' => $owner->id,
                'fieldHandle' => $field->handle,
                'parentBlockId' => $parentBlockId,
                'blocksCreated' => count($newBlocks),
                'blockIds' => array_map(static fn (Block $block): ?int => $block->id, $newBlocks),
                'diff' => NeoBlockTree::diff($summaries, $insertionIndex, $flatNew),
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
     * Recursively validate a normalized tree against the field: every node's
     * block type must exist, its field handles must be known, and any children
     * must be permitted by the node's childBlocks rule.
     *
     * @param array<string, mixed> $node
     * @throws ToolCallException
     */
    private function validateTree(NeoField $field, array $node): void {
        $blockType = (string) $node['blockType'];
        $blockTypeModel = $this->resolveBlockType($field, $blockType);

        NeoBlockPayload::assertKnownHandles(
            is_array($node['fields'] ?? null) ? $node['fields'] : [],
            $this->allowedFieldHandles($blockTypeModel),
            $blockType,
        );

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            return;
        }

        $this->assertChildrenAllowed($blockTypeModel, $blockType, $children);

        foreach ($children as $child) {
            $this->validateTree($field, $child);
        }
    }

    /**
     * Assert a block type permits the given child payloads by its childBlocks
     * rule.
     *
     * @param array<int, array<string, mixed>> $children
     * @throws ToolCallException
     */
    private function assertChildrenAllowed(object $blockTypeModel, string $parentType, array $children): void {
        $childBlocks = NeoSerializer::nesting($blockTypeModel)['childBlocks'] ?? null;

        if (!NeoBlockTree::parentAllowsChildren($childBlocks)) {
            throw new ToolCallException(
                "Block type '{$parentType}' does not allow child blocks, but children were provided.",
            );
        }

        foreach ($children as $child) {
            $childType = (string) ($child['blockType'] ?? '');
            if (!NeoBlockTree::childBlocksAllows($childBlocks, $childType)) {
                throw new ToolCallException(
                    "Block type '{$parentType}' does not allow child blocks of type '{$childType}'.",
                );
            }
        }
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
     * Resolve the insertion scope: either the top level, or inside an existing
     * parent block (validated to exist and to permit the root child type).
     *
     * @param array<int, array<string, mixed>> $summaries
     * @param array<int, object> $existing
     * @return array{start: int, end: int, level: int}
     * @throws ToolCallException
     */
    private function resolveScope(
        array $summaries,
        array $existing,
        NeoField $field,
        ?int $parentBlockId,
        string $rootType,
    ): array {
        if ($parentBlockId === null) {
            return ['start' => 0, 'end' => count($summaries), 'level' => 1];
        }

        $index = NeoBlockTree::findIndexById($summaries, $parentBlockId);
        if ($index === null) {
            throw new ToolCallException(
                "Parent block ID {$parentBlockId} was not found in field '{$field->handle}'.",
            );
        }

        $this->assertParentAccepts($existing[$index], $parentBlockId, $rootType);

        return [
            'start' => $index + 1,
            'end' => NeoBlockTree::subtreeEnd($summaries, $index),
            'level' => (int) ($summaries[$index]['level'] ?? 1) + 1,
        ];
    }

    /**
     * Assert an existing parent block permits a child of the given type.
     *
     * @throws ToolCallException
     */
    private function assertParentAccepts(object $parentBlock, int $parentBlockId, string $rootType): void {
        $childBlocks = $this->childBlocksRule($parentBlock);

        if (!NeoBlockTree::parentAllowsChildren($childBlocks)) {
            throw new ToolCallException("Parent block {$parentBlockId} does not allow child blocks.");
        }

        if (!NeoBlockTree::childBlocksAllows($childBlocks, $rootType)) {
            throw new ToolCallException(
                "Parent block {$parentBlockId} does not allow child blocks of type '{$rootType}'.",
            );
        }
    }

    /**
     * Read a block's childBlocks rule via its block type, duck-typed.
     */
    private function childBlocksRule(object $block): mixed {
        if (!method_exists($block, 'getType')) {
            return null;
        }

        try {
            $type = $block->getType();
        } catch (Throwable) {
            return null;
        }

        if (!is_object($type)) {
            return null;
        }

        return NeoSerializer::nesting($type)['childBlocks'] ?? null;
    }

    /**
     * Build (unsaved) Block elements for a flattened tree, in preorder.
     *
     * @param array<int, array{type: string, level: int, fields: array<string, mixed>}> $flatNew
     * @return array<int, Block>
     * @throws ToolCallException
     */
    private function buildTreeBlocks(ElementInterface $owner, NeoField $field, array $flatNew): array {
        $blocks = [];

        foreach ($flatNew as $item) {
            $blockTypeModel = $this->resolveBlockType($field, (string) $item['type']);
            $blocks[] = $this->buildBlock(
                $owner,
                $field,
                $blockTypeModel,
                $item['fields'],
                (int) $item['level'],
            );
        }

        return $blocks;
    }

    /**
     * Create a single unsaved Neo Block element with its level and values set.
     *
     * @param array<string, mixed> $fieldValues
     */
    private function buildBlock(
        ElementInterface $owner,
        NeoField $field,
        object $blockTypeModel,
        array $fieldValues,
        int $level,
    ): Block {
        $block = new Block();
        $block->fieldId = $field->id;
        $block->typeId = $blockTypeModel->id;
        $block->ownerId = $owner->id;
        $block->siteId = $owner->siteId;
        $block->level = $level;
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

        return $block;
    }

    /**
     * Splice the new blocks into the owner's existing block list at the
     * resolved index and resave the owner so Neo rebuilds the field structure
     * (levels + lft/rgt) from the ordered array (mirrors Neo Field::saveValue).
     *
     * @param array<int, object> $existing
     * @param array<int, Block> $newBlocks
     * @throws ToolCallException
     */
    private function persistTree(
        ElementInterface $owner,
        NeoField $field,
        array $existing,
        array $newBlocks,
        int $insertionIndex,
    ): void {
        $merged = [
            ...array_slice($existing, 0, $insertionIndex),
            ...$newBlocks,
            ...array_slice($existing, $insertionIndex),
        ];

        $owner->setFieldValue((string) $field->handle, $merged);

        if (!Craft::$app->getElements()->saveElement($owner)) {
            throw new ToolCallException(
                'Failed to save Neo blocks: ' . json_encode($owner->getErrors()),
            );
        }
    }
}
