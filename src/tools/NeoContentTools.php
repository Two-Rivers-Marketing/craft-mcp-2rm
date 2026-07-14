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

        $this->persistBlocks($owner, $field, $merged);
    }

    /**
     * Set the owner's Neo field to the given ordered block list and resave the
     * owner so Neo rebuilds the field structure (levels + lft/rgt) from the
     * ordered array. Shared by create/update/reorder/delete write paths.
     *
     * @param array<int, object> $blocks
     * @throws ToolCallException
     */
    private function persistBlocks(ElementInterface $owner, NeoField $field, array $blocks): void {
        $owner->setFieldValue((string) $field->handle, $this->toNeoValue($blocks));

        if (!Craft::$app->getElements()->saveElement($owner)) {
            throw new ToolCallException(
                'Failed to save Neo blocks: ' . json_encode($owner->getErrors()),
            );
        }
    }

    /**
     * Serialize an ordered (preorder) list of Neo Block elements into the array
     * shape Neo's Field::normalizeValue expects: a delta value of
     * {blocks: {<id|newN> => {type, enabled, level, fields}}, sortOrder: [...]}.
     *
     * Passing Block elements straight to setFieldValue is NOT supported — Neo
     * treats each as serialized array data and evaluates $block['type'], which
     * returns the BlockType object and blows up as an array offset
     * (Field.php "Cannot access offset of type ...BlockType"). Existing blocks
     * keep their real id key so Neo updates them in place; new blocks (no id)
     * get sequential newN keys so Neo creates them.
     *
     * @param array<int, object> $blocks
     * @return array{blocks: array<string, array<string, mixed>>, sortOrder: array<int, string>}
     */
    private function toNeoValue(array $blocks): array {
        $data = [];
        $sortOrder = [];
        $new = 0;

        foreach ($blocks as $block) {
            $key = $block->id ? (string) $block->id : 'new' . (++$new);
            $data[$key] = [
                'type' => $block->getType()->handle,
                'enabled' => (bool) $block->enabled,
                'level' => (int) $block->level,
                'fields' => $block->getSerializedFieldValues(),
            ];
            $sortOrder[] = $key;
        }

        return ['blocks' => $data, 'sortOrder' => $sortOrder];
    }

    /**
     * Update the field values of a single existing Neo block, addressed by its
     * block ID (from a prior read). Only the given field handles are changed.
     */
    #[McpTool(
        name: 'update_neo_block',
        description: 'Update field values on a single existing Neo block, addressed by its block ID (from a prior read such as describe_content_builder). fields is a JSON object of fieldHandle => value pairs; ONLY those handles are changed, every other field and the block\'s position are left untouched. Field handles are validated against the block type\'s layout (a clear error lists the valid handles). Writes target the entry\'s canonical element so the change appears live. Pass dryRun: true to preview an old->new diff per changed field without saving.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateNeoBlock(
        int $blockId,
        string $fields,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($blockId, $fields, $dryRun): array {
            $this->assertNeoAvailable();

            $ctx = $this->resolveBlockContext($blockId);
            $newValues = NeoBlockPayload::decode($fields);

            if ($newValues === []) {
                throw new ToolCallException(
                    'fields must include at least one fieldHandle => value pair to update.',
                );
            }

            $blockType = $this->blockTypeOf($ctx['block']);
            NeoBlockPayload::assertKnownHandles(
                $newValues,
                $this->allowedFieldHandles($blockType),
                (string) ($blockType->handle ?? ''),
            );

            $oldValues = $this->readFieldValues($ctx['block'], array_keys($newValues));
            $fieldDiff = NeoBlockPayload::fieldDiff($oldValues, $newValues);
            $diff = [
                'block' => [
                    'id' => $ctx['blockId'],
                    'type' => NeoBlockPayload::summarizeBlock($ctx['block'])['type'],
                ],
                'fields' => $fieldDiff,
            ];

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'entryId' => $ctx['owner']->id,
                    'fieldHandle' => $ctx['field']->handle,
                    'blockId' => $ctx['blockId'],
                    'diff' => $diff,
                ]);
            }

            $this->applyFieldValues($ctx['block'], $newValues);
            $this->persistBlocks($ctx['owner'], $ctx['field'], $ctx['existing']);

            return Response::success([
                'entryId' => $ctx['owner']->id,
                'fieldHandle' => $ctx['field']->handle,
                'blockId' => $ctx['blockId'],
                'fieldsUpdated' => array_keys($fieldDiff['changed']),
                'diff' => $diff,
            ]);
        });
    }

    /**
     * Reorder the top-level Neo blocks of an entry, either by a full desired
     * order or a single move instruction.
     */
    #[McpTool(
        name: 'reorder_neo_blocks',
        description: 'Reorder the blocks in an entry\'s Neo content builder field. Provide EXACTLY ONE of: order — a JSON array of top-level block IDs in the desired order, which must be a permutation of the current top-level IDs (an error lists any missing/unexpected); or move — a JSON object {"blockId": <id>, "position": <index|"before:<id>"|"after:<id>">} that moves one block within its sibling scope. Moving a block moves its whole subtree; a before:/after: reference inside the moved subtree is rejected. fieldHandle selects the Neo field when the entry has more than one. Writes target the entry\'s canonical element. Pass dryRun: true to preview the before/after order without saving.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function reorderNeoBlocks(
        int $entryId,
        ?string $fieldHandle = null,
        ?string $order = null,
        ?string $move = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($entryId, $fieldHandle, $order, $move, $dryRun): array {
            $this->assertNeoAvailable();

            $owner = $this->resolveCanonicalOwner($entryId);
            $field = $this->resolveBuilderField($entryId, $fieldHandle);

            $existing = $this->existingBlocks($owner, (string) $field->handle);
            $summaries = array_map(NeoBlockPayload::summarizeBlock(...), $existing);

            $newOrder = $this->resolveReorder($summaries, $order, $move);
            $afterSummaries = array_map(static fn (int $i): array => $summaries[$i], $newOrder);
            $diff = NeoBlockTree::reorderDiff($summaries, $afterSummaries);

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'entryId' => $owner->id,
                    'fieldHandle' => $field->handle,
                    'diff' => $diff,
                ]);
            }

            $reordered = array_map(static fn (int $i): object => $existing[$i], $newOrder);
            $this->persistBlocks($owner, $field, $reordered);

            return Response::success([
                'entryId' => $owner->id,
                'fieldHandle' => $field->handle,
                'blocksReordered' => count($reordered),
                'diff' => $diff,
            ]);
        });
    }

    /**
     * Delete a single Neo block and all of its descendants, addressed by its
     * block ID (from a prior read).
     */
    #[McpTool(
        name: 'delete_neo_block',
        description: 'Delete a single Neo block AND all of its descendants from an entry\'s content builder, addressed by its block ID (from a prior read such as describe_content_builder). Writes target the entry\'s canonical element so the change appears live. Pass dryRun: true to preview the flattened before/after block lists (and the removed subtree) without saving.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function deleteNeoBlock(
        int $blockId,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($blockId, $dryRun): array {
            $this->assertNeoAvailable();

            $ctx = $this->resolveBlockContext($blockId);
            $start = $ctx['index'];
            $end = NeoBlockTree::subtreeEnd($ctx['summaries'], $start);
            $diff = NeoBlockTree::deleteDiff($ctx['summaries'], $start, $end);

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'entryId' => $ctx['owner']->id,
                    'fieldHandle' => $ctx['field']->handle,
                    'blockId' => $ctx['blockId'],
                    'diff' => $diff,
                ]);
            }

            $remaining = [
                ...array_slice($ctx['existing'], 0, $start),
                ...array_slice($ctx['existing'], $end),
            ];
            $this->persistBlocks($ctx['owner'], $ctx['field'], $remaining);

            return Response::success([
                'entryId' => $ctx['owner']->id,
                'fieldHandle' => $ctx['field']->handle,
                'blockId' => $ctx['blockId'],
                'blocksDeleted' => $end - $start,
                'diff' => $diff,
            ]);
        });
    }

    /**
     * Resolve a block ID to its canonical owner, field and position.
     *
     * Routes through canonical elements (Craft 5 canonical/derivative) just
     * like create: the block's owner is resolved to its canonical element and
     * the block is located by its canonical ID within that owner's field.
     *
     * @return array{blockId: int, block: object, field: NeoField, owner: ElementInterface, existing: array<int, object>, summaries: array<int, array<string, mixed>>, index: int}
     * @throws ToolCallException
     */
    private function resolveBlockContext(int $blockId): array {
        $block = Craft::$app->getElements()->getElementById($blockId);

        if (!$block instanceof Block) {
            throw new ToolCallException("Neo block with ID {$blockId} not found.");
        }

        $field = Craft::$app->getFields()->getFieldById((int) $block->fieldId);
        if (!$field instanceof NeoField) {
            throw new ToolCallException("Neo block {$blockId} does not belong to a Neo field.");
        }

        $owner = $this->resolveBlockOwner($block);
        $canonicalId = (int) $block->getCanonicalId();
        $existing = $this->existingBlocks($owner, (string) $field->handle);
        $summaries = array_map(NeoBlockPayload::summarizeBlock(...), $existing);

        $index = NeoBlockTree::findIndexById($summaries, $canonicalId);
        if ($index === null) {
            throw new ToolCallException(
                "Block ID {$canonicalId} was not found in field '{$field->handle}' on entry {$owner->id}.",
            );
        }

        return [
            'blockId' => $canonicalId,
            'block' => $existing[$index],
            'field' => $field,
            'owner' => $owner,
            'existing' => $existing,
            'summaries' => $summaries,
            'index' => $index,
        ];
    }

    /**
     * Resolve a block's canonical owner element.
     *
     * @throws ToolCallException
     */
    private function resolveBlockOwner(Block $block): ElementInterface {
        $ownerId = $block->primaryOwnerId ?? $block->ownerId;

        if ($ownerId === null) {
            throw new ToolCallException('Unable to resolve the owner element for the target block.');
        }

        $owner = Craft::$app->getElements()->getElementById((int) $ownerId);
        if ($owner === null) {
            throw new ToolCallException("Owner element {$ownerId} for the target block not found.");
        }

        return $owner->getCanonical();
    }

    /**
     * Resolve the block type model of an existing block.
     *
     * @throws ToolCallException
     */
    private function blockTypeOf(object $block): object {
        if (!method_exists($block, 'getType')) {
            throw new ToolCallException('Unable to resolve the block type for the target block.');
        }

        $type = $block->getType();
        if (!is_object($type)) {
            throw new ToolCallException('Unable to resolve the block type for the target block.');
        }

        return $type;
    }

    /**
     * Read the current values of the given field handles from a block.
     *
     * @param array<int, string> $handles
     * @return array<string, mixed>
     */
    private function readFieldValues(object $block, array $handles): array {
        $values = [];

        foreach ($handles as $handle) {
            $values[(string) $handle] = $this->readFieldValue($block, (string) $handle);
        }

        return $values;
    }

    /**
     * Read a single field value from a block, coerced to a JSON-safe form.
     */
    private function readFieldValue(object $block, string $handle): mixed {
        if (!method_exists($block, 'getFieldValue')) {
            return null;
        }

        try {
            $value = $block->getFieldValue($handle);
        } catch (Throwable) {
            return null;
        }

        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '(complex value)';
    }

    /**
     * Apply new field values to an existing block, duck-typed.
     */
    private function applyFieldValues(object $block, array $values): void {
        if (method_exists($block, 'setFieldValues')) {
            $block->setFieldValues($values);
        }
    }

    /**
     * Resolve the reorder request to a new flat index ordering. Exactly one of
     * order / move must be provided.
     *
     * @param array<int, array<string, mixed>> $summaries
     * @return array<int, int>
     * @throws ToolCallException
     */
    private function resolveReorder(array $summaries, ?string $order, ?string $move): array {
        $hasOrder = $order !== null && trim($order) !== '';
        $hasMove = $move !== null && trim($move) !== '';

        if ($hasOrder === $hasMove) {
            throw new ToolCallException(
                'Provide exactly one of order (a JSON array of top-level block IDs) '
                . 'or move (a JSON object {blockId, position}).',
            );
        }

        if ($hasOrder) {
            return NeoBlockTree::orderIndexes($summaries, NeoBlockTree::decodeOrder($order));
        }

        $moveSpec = NeoBlockTree::decodeMove($move);

        return NeoBlockTree::moveIndexes($summaries, $moveSpec['blockId'], $moveSpec['position']);
    }
}
