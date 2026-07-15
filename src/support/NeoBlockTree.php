<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use Mcp\Exception\ToolCallException;

/**
 * Pure-logic helpers for nested Neo block trees and positioning (issue #9).
 *
 * Neo stores blocks as a flat, ordered list where each block carries a
 * `level`; the tree is derived from level + preorder position (a block's
 * descendants immediately follow it with a higher level). These helpers work
 * purely on plain arrays — no Craft or Neo dependency — so the tree
 * flattening, position resolution and diff construction are fully
 * unit-testable even though the Neo plugin is not installed here.
 *
 * A "node" is the recursive payload shape accepted by create_neo_block:
 *   ['blockType' => string, 'fields' => array<string,mixed>, 'children' => node[]]
 *
 * A "flat item" is one entry of the flattened list:
 *   ['type' => string, 'level' => int, 'fields' => array]
 *
 * Positioning reuse (issue #10): resolveInsertionIndex(), subtreeEnd() and
 * findIndexById() operate on flat summary lists, so reorder/move can compute
 * source and destination indices the same way.
 *
 * @author 2RM
 */
final class NeoBlockTree {
    /**
     * Decode the `children` JSON argument into a list of raw node arrays.
     *
     * @return array<int, mixed>
     * @throws ToolCallException When the JSON is invalid or not a list
     */
    public static function decodeChildren(?string $childrenJson): array {
        if ($childrenJson === null || trim($childrenJson) === '') {
            return [];
        }

        $decoded = json_decode($childrenJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ToolCallException('Invalid JSON in children parameter: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new ToolCallException(
                'children must be a JSON array of block payloads, e.g. [{"blockType": "columnItem"}]',
            );
        }

        if ($decoded !== [] && !array_is_list($decoded)) {
            throw new ToolCallException('children must be a JSON array (list) of block payloads, not an object.');
        }

        return $decoded;
    }

    /**
     * Validate and normalize a whole tree from a root block type + fields and
     * a list of raw child payloads.
     *
     * @param array<string, mixed> $rootFields
     * @param array<int, mixed> $rawChildren
     * @return array{blockType: string, fields: array<string, mixed>, children: array<int, array<string, mixed>>}
     * @throws ToolCallException On any malformed node in the tree
     */
    public static function normalizeTree(string $rootType, array $rootFields, array $rawChildren): array {
        return self::normalizeNode(
            ['blockType' => $rootType, 'fields' => $rootFields, 'children' => $rawChildren],
            'block',
        );
    }

    /**
     * Flatten a normalized node into a preorder list of flat items.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{type: string, level: int, fields: array<string, mixed>}>
     */
    public static function flatten(array $node, int $level): array {
        $flat = [[
            'type' => (string) $node['blockType'],
            'level' => $level,
            'fields' => is_array($node['fields'] ?? null) ? $node['fields'] : [],
        ]];

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $flat = [...$flat, ...self::flatten($child, $level + 1)];
        }

        return $flat;
    }

    /**
     * Resolve a position string to an insertion index within a flat list.
     *
     * Position forms:
     * - null/blank: append at the end of the scope ($rangeEnd)
     * - integer "N": before the N-th sibling in the scope (N == count appends)
     * - "before:<id>": at the start of the referenced sibling's subtree
     * - "after:<id>": just past the referenced sibling's subtree
     *
     * @param array<int, array<string, mixed>> $flat Flat summaries carrying 'id' and 'level'
     * @throws ToolCallException On unknown reference, malformed position, or out-of-range index
     */
    public static function resolveInsertionIndex(
        array $flat,
        ?string $position,
        int $rangeStart,
        int $rangeEnd,
        int $level,
    ): int {
        if ($position === null || trim($position) === '') {
            return $rangeEnd;
        }

        $position = trim($position);
        $siblings = self::siblingStarts($flat, $rangeStart, $rangeEnd, $level);

        if (str_starts_with($position, 'before:')) {
            return self::siblingById($siblings, substr($position, 7), $position)['index'];
        }

        if (str_starts_with($position, 'after:')) {
            return self::subtreeEnd($flat, self::siblingById($siblings, substr($position, 6), $position)['index']);
        }

        return self::integerPosition($position, $siblings, $rangeEnd);
    }

    /**
     * Decode the `order` JSON argument into a list of integer block IDs.
     *
     * @return array<int, int>
     * @throws ToolCallException When the JSON is invalid, empty, or not a list of ints
     */
    public static function decodeOrder(?string $orderJson): array {
        if ($orderJson === null || trim($orderJson) === '') {
            throw new ToolCallException('order must be a JSON array of top-level block IDs, e.g. [12, 9, 15].');
        }

        $decoded = json_decode($orderJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ToolCallException('Invalid JSON in order parameter: ' . json_last_error_msg());
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ToolCallException('order must be a JSON array (list) of block IDs, e.g. [12, 9, 15].');
        }

        if ($decoded === []) {
            throw new ToolCallException('order must contain at least one block ID.');
        }

        return array_map(self::intId(...), $decoded);
    }

    /**
     * Decode the `move` JSON argument into a {blockId, position} instruction.
     *
     * @return array{blockId: int, position: string}
     * @throws ToolCallException When the JSON is invalid or missing blockId/position
     */
    public static function decodeMove(?string $moveJson): array {
        if ($moveJson === null || trim($moveJson) === '') {
            throw new ToolCallException('move must be a JSON object {blockId, position}.');
        }

        $decoded = json_decode($moveJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ToolCallException('Invalid JSON in move parameter: ' . json_last_error_msg());
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ToolCallException(
                'move must be a JSON object with blockId and position, e.g. {"blockId": 12, "position": "before:9"}.',
            );
        }

        if (!array_key_exists('blockId', $decoded)) {
            throw new ToolCallException('move requires a blockId.');
        }

        if (!array_key_exists('position', $decoded)) {
            throw new ToolCallException('move requires a position (an integer index, or before:<id> / after:<id>).');
        }

        return [
            'blockId' => self::intId($decoded['blockId']),
            'position' => self::normalizePosition($decoded['position']),
        ];
    }

    /**
     * Compute the new flat index order after reordering top-level blocks.
     *
     * The requested order must be a permutation of the current top-level block
     * IDs; each block carries its whole subtree with it.
     *
     * @param array<int, array<string, mixed>> $flat
     * @param array<int, int> $order
     * @return array<int, int> Original flat indices in the new order
     * @throws ToolCallException When the order is not a permutation of the top-level IDs
     */
    public static function orderIndexes(array $flat, array $order): array {
        self::assertPermutation(self::topLevelIds($flat), $order);

        $result = [];
        foreach ($order as $id) {
            $index = (int) self::findIndexById($flat, $id);
            $end = self::subtreeEnd($flat, $index);
            $result = [...$result, ...range($index, $end - 1)];
        }

        return $result;
    }

    /**
     * Compute the new flat index order after moving one block's subtree to a
     * new position among its siblings.
     *
     * @param array<int, array<string, mixed>> $flat
     * @return array<int, int> Original flat indices in the new order
     * @throws ToolCallException When the block is missing or the position references the moved subtree
     */
    public static function moveIndexes(array $flat, int $blockId, ?string $position): array {
        $index = self::findIndexById($flat, $blockId);
        if ($index === null) {
            throw new ToolCallException("Block ID {$blockId} was not found among the blocks to reorder.");
        }

        $subtreeEnd = self::subtreeEnd($flat, $index);
        $scope = self::siblingScope($flat, $index);

        self::assertReferenceOutsideRange($flat, $position, $index, $subtreeEnd);

        $insertionIndex = self::resolveInsertionIndex(
            $flat,
            $position,
            $scope['start'],
            $scope['end'],
            $scope['level'],
        );

        return self::spliceIndexRange(count($flat), $index, $subtreeEnd, $insertionIndex);
    }

    /**
     * The sibling scope (flat range + level) of the block at $index: the range
     * of its parent's children, or the whole list for a top-level block.
     *
     * @param array<int, array<string, mixed>> $flat
     * @return array{start: int, end: int, level: int}
     */
    public static function siblingScope(array $flat, int $index): array {
        $level = (int) ($flat[$index]['level'] ?? 1);

        if ($level <= 1) {
            return ['start' => 0, 'end' => count($flat), 'level' => 1];
        }

        $parentIndex = self::parentIndex($flat, $index);
        if ($parentIndex === null) {
            return ['start' => 0, 'end' => count($flat), 'level' => $level];
        }

        return [
            'start' => $parentIndex + 1,
            'end' => self::subtreeEnd($flat, $parentIndex),
            'level' => $level,
        ];
    }

    /**
     * Return the flat index just past the subtree rooted at $start.
     *
     * @param array<int, array<string, mixed>> $flat
     */
    public static function subtreeEnd(array $flat, int $start): int {
        $level = (int) ($flat[$start]['level'] ?? 0);
        $count = count($flat);
        $i = $start + 1;

        while ($i < $count && (int) ($flat[$i]['level'] ?? 0) > $level) {
            $i++;
        }

        return $i;
    }

    /**
     * Find the flat index of the block with the given id, or null.
     *
     * @param array<int, array<string, mixed>> $flat
     */
    public static function findIndexById(array $flat, int $id): ?int {
        foreach ($flat as $i => $item) {
            if ((int) ($item['id'] ?? PHP_INT_MIN) === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether a block type's childBlocks rule permits any children at all.
     *
     * Neo stores childBlocks as `'*'`/true (any) or a non-empty list of
     * allowed handles when child blocks are enabled, and null/false/empty when
     * they are not. A leaf block type reports childBlocks as **null** (verified
     * against Neo 5.5), so null must be treated as a hard "no children" — the
     * same as false or an empty list — otherwise illegal nesting under any leaf
     * slips through.
     */
    public static function parentAllowsChildren(mixed $childBlocks): bool {
        if ($childBlocks === false || $childBlocks === null) {
            return false;
        }

        if (is_array($childBlocks)) {
            return $childBlocks !== [];
        }

        return true;
    }

    /**
     * Whether a block type's childBlocks rule permits a specific child type.
     *
     * A list of handles is checked for membership; `true`/`'*'` permit any
     * type; an explicit false or a leaf's null permit none.
     */
    public static function childBlocksAllows(mixed $childBlocks, string $childType): bool {
        if (is_array($childBlocks)) {
            return in_array($childType, $childBlocks, true);
        }

        return $childBlocks !== false && $childBlocks !== null;
    }

    /**
     * Build a before/after diff for inserting a flattened subtree at an index.
     *
     * @param array<int, array<string, mixed>> $beforeFlat Existing block summaries in flat order
     * @param array<int, array{type: string, level: int, fields: array<string, mixed>}> $insertedFlat
     * @return array<string, mixed>
     */
    public static function diff(array $beforeFlat, int $insertionIndex, array $insertedFlat): array {
        $marked = array_map(
            static fn (array $item): array => [...$item, 'id' => null, 'new' => true],
            $insertedFlat,
        );

        $after = [
            ...array_slice($beforeFlat, 0, $insertionIndex),
            ...$marked,
            ...array_slice($beforeFlat, $insertionIndex),
        ];

        return [
            'before' => [
                'blockCount' => count($beforeFlat),
                'blocks' => array_values($beforeFlat),
            ],
            'after' => [
                'blockCount' => count($after),
                'blocks' => array_values($after),
            ],
            'inserted' => [
                'at' => $insertionIndex,
                'blockCount' => count($marked),
                'blocks' => array_values($marked),
            ],
        ];
    }

    /**
     * Build a before/after diff for a pure reordering (same blocks, new order).
     *
     * @param array<int, array<string, mixed>> $beforeFlat
     * @param array<int, array<string, mixed>> $afterFlat
     * @return array<string, mixed>
     */
    public static function reorderDiff(array $beforeFlat, array $afterFlat): array {
        return [
            'before' => [
                'blockCount' => count($beforeFlat),
                'blocks' => array_values($beforeFlat),
            ],
            'after' => [
                'blockCount' => count($afterFlat),
                'blocks' => array_values($afterFlat),
            ],
        ];
    }

    /**
     * Build a before/after diff for deleting the subtree range [start, end).
     *
     * @param array<int, array<string, mixed>> $beforeFlat
     * @return array<string, mixed>
     */
    public static function deleteDiff(array $beforeFlat, int $start, int $end): array {
        $removed = array_slice($beforeFlat, $start, $end - $start);
        $after = [
            ...array_slice($beforeFlat, 0, $start),
            ...array_slice($beforeFlat, $end),
        ];

        return [
            'before' => [
                'blockCount' => count($beforeFlat),
                'blocks' => array_values($beforeFlat),
            ],
            'after' => [
                'blockCount' => count($after),
                'blocks' => array_values($after),
            ],
            'removed' => [
                'blockCount' => count($removed),
                'blocks' => array_values($removed),
            ],
        ];
    }

    /**
     * Diff a post-save flattened block list against the pre-save list and
     * return the IDs of the newly created blocks, in document (lft) order.
     *
     * Neo creates fresh block records from the serialized delta value, so the
     * pre-save Block objects never receive IDs — re-reading the owner after
     * the save and diffing against the pre-save ID set is the only way to
     * report the real IDs, and it is robust to any insertion position.
     *
     * @param array<int, array<string, mixed>> $beforeFlat Summaries before the save (see NeoBlockPayload::summarizeBlock())
     * @param array<int, array<string, mixed>> $afterFlat Summaries after the save, in document order
     * @return array<int, int>
     */
    public static function newBlockIds(array $beforeFlat, array $afterFlat): array {
        $known = [];

        foreach ($beforeFlat as $block) {
            if (!is_numeric($block['id'] ?? null)) {
                continue;
            }

            $known[(int) $block['id']] = true;
        }

        $created = [];

        foreach ($afterFlat as $block) {
            $id = $block['id'] ?? null;

            if (!is_numeric($id) || isset($known[(int) $id])) {
                continue;
            }

            $created[] = (int) $id;
        }

        return $created;
    }

    /**
     * Recursively validate + normalize one node payload.
     *
     * @return array{blockType: string, fields: array<string, mixed>, children: array<int, array<string, mixed>>}
     * @throws ToolCallException
     */
    private static function normalizeNode(mixed $raw, string $path): array {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new ToolCallException("Malformed block payload at {$path}: expected an object with a 'blockType'.");
        }

        $blockType = $raw['blockType'] ?? null;
        if (!is_string($blockType) || trim($blockType) === '') {
            throw new ToolCallException("Malformed block payload at {$path}: 'blockType' must be a non-empty string.");
        }

        $fields = $raw['fields'] ?? [];
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw new ToolCallException(
                "Malformed block payload at {$path}: 'fields' must be an object of fieldHandle => value pairs.",
            );
        }

        $rawChildren = $raw['children'] ?? [];
        if (!is_array($rawChildren) || ($rawChildren !== [] && !array_is_list($rawChildren))) {
            throw new ToolCallException(
                "Malformed block payload at {$path}: 'children' must be an array of block payloads.",
            );
        }

        return [
            'blockType' => $blockType,
            'fields' => $fields,
            'children' => self::normalizeChildren($rawChildren, $path),
        ];
    }

    /**
     * Normalize a list of child payloads.
     *
     * @param array<int, mixed> $rawChildren
     * @return array<int, array<string, mixed>>
     * @throws ToolCallException
     */
    private static function normalizeChildren(array $rawChildren, string $path): array {
        $children = [];

        foreach ($rawChildren as $i => $child) {
            $children[] = self::normalizeNode($child, "{$path}.children[{$i}]");
        }

        return $children;
    }

    /**
     * Collect sibling start entries at a given level within a flat range.
     *
     * @param array<int, array<string, mixed>> $flat
     * @return array<int, array{index: int, id: mixed}>
     */
    private static function siblingStarts(array $flat, int $rangeStart, int $rangeEnd, int $level): array {
        $starts = [];
        $i = $rangeStart;

        while ($i < $rangeEnd) {
            if ((int) ($flat[$i]['level'] ?? 0) !== $level) {
                $i++;
                continue;
            }

            $starts[] = ['index' => $i, 'id' => $flat[$i]['id'] ?? null];
            $i = self::subtreeEnd($flat, $i);
        }

        return $starts;
    }

    /**
     * Find a sibling by its (numeric) block ID for a before:/after: reference.
     *
     * @param array<int, array{index: int, id: mixed}> $siblings
     * @return array{index: int, id: mixed}
     * @throws ToolCallException
     */
    private static function siblingById(array $siblings, string $rawId, string $position): array {
        $id = trim($rawId);
        if ($id === '' || !ctype_digit($id)) {
            throw new ToolCallException(
                "Invalid position reference '{$position}': expected before:<blockId> or after:<blockId> with a numeric block ID.",
            );
        }

        foreach ($siblings as $sibling) {
            if ((string) ($sibling['id'] ?? '') === $id) {
                return $sibling;
            }
        }

        throw new ToolCallException(
            "Position reference block ID {$id} is not an existing sibling in this scope. "
            . 'Available sibling block IDs: ' . self::siblingIdList($siblings),
        );
    }

    /**
     * Resolve a bare integer position to an insertion index.
     *
     * @param array<int, array{index: int, id: mixed}> $siblings
     * @throws ToolCallException
     */
    private static function integerPosition(string $position, array $siblings, int $rangeEnd): int {
        if (preg_match('/^-?\d+$/', $position) !== 1) {
            throw new ToolCallException(
                "Invalid position '{$position}': use an integer index, before:<blockId>, or after:<blockId>.",
            );
        }

        $index = (int) $position;
        $count = count($siblings);

        if ($index < 0 || $index > $count) {
            throw new ToolCallException("Position index {$index} is out of range (valid: 0 to {$count}).");
        }

        if ($index === $count) {
            return $rangeEnd;
        }

        return $siblings[$index]['index'];
    }

    /**
     * The flat index of the nearest ancestor of the block at $index (the block
     * one level up that precedes it), or null when it is top-level.
     *
     * @param array<int, array<string, mixed>> $flat
     */
    private static function parentIndex(array $flat, int $index): ?int {
        $level = (int) ($flat[$index]['level'] ?? 1);

        for ($i = $index - 1; $i >= 0; $i--) {
            if ((int) ($flat[$i]['level'] ?? 0) === $level - 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The IDs of the top-level (level 1) blocks in flat order.
     *
     * @param array<int, array<string, mixed>> $flat
     * @return array<int, int>
     */
    private static function topLevelIds(array $flat): array {
        $ids = [];

        foreach ($flat as $item) {
            if ((int) ($item['level'] ?? 0) === 1) {
                $ids[] = (int) ($item['id'] ?? 0);
            }
        }

        return $ids;
    }

    /**
     * Assert the requested order is a permutation of the current top-level IDs.
     *
     * @param array<int, int> $current
     * @param array<int, int> $requested
     * @throws ToolCallException Listing missing and extra IDs
     */
    private static function assertPermutation(array $current, array $requested): void {
        $missing = array_values(array_diff($current, $requested));
        $extra = array_values(array_diff($requested, $current));

        if ($missing === [] && $extra === [] && count($requested) === count($current)) {
            return;
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing: ' . implode(', ', $missing);
        }

        if ($extra !== []) {
            $parts[] = 'unexpected: ' . implode(', ', $extra);
        }

        if ($parts === []) {
            $parts[] = 'duplicate block IDs are not allowed';
        }

        throw new ToolCallException(
            'order must be a permutation of the current top-level block IDs ('
            . implode(', ', $current) . '). Problems — ' . implode('; ', $parts) . '.',
        );
    }

    /**
     * Reject a before:/after: reference that points inside the moved subtree
     * range [start, end) (the moved block itself or one of its descendants).
     *
     * @param array<int, array<string, mixed>> $flat
     * @throws ToolCallException
     */
    private static function assertReferenceOutsideRange(array $flat, ?string $position, int $start, int $end): void {
        $refId = self::referencedId($position);
        if ($refId === null) {
            return;
        }

        $refIndex = self::findIndexById($flat, $refId);
        if ($refIndex === null) {
            return;
        }

        if ($refIndex >= $start && $refIndex < $end) {
            throw new ToolCallException(
                "Cannot move a block relative to itself or one of its own descendants (position '{$position}').",
            );
        }
    }

    /**
     * Extract the numeric block ID referenced by a before:/after: position, or
     * null when the position is not a numeric reference.
     */
    private static function referencedId(?string $position): ?int {
        if ($position === null) {
            return null;
        }

        $position = trim($position);
        $raw = match (true) {
            str_starts_with($position, 'before:') => substr($position, 7),
            str_starts_with($position, 'after:') => substr($position, 6),
            default => null,
        };

        if ($raw === null || !ctype_digit(trim($raw))) {
            return null;
        }

        return (int) trim($raw);
    }

    /**
     * Produce a new index ordering by removing the contiguous range
     * [start, end) and reinserting it at $insertionIndex (original coordinates).
     *
     * @return array<int, int>
     */
    private static function spliceIndexRange(int $count, int $start, int $end, int $insertionIndex): array {
        $all = $count > 0 ? range(0, $count - 1) : [];
        $moved = array_slice($all, $start, $end - $start);
        $rest = [
            ...array_slice($all, 0, $start),
            ...array_slice($all, $end),
        ];

        $restInsert = $insertionIndex <= $start
            ? $insertionIndex
            : $insertionIndex - ($end - $start);

        return [
            ...array_slice($rest, 0, $restInsert),
            ...$moved,
            ...array_slice($rest, $restInsert),
        ];
    }

    /**
     * Coerce a JSON value into an integer block ID.
     *
     * @throws ToolCallException
     */
    private static function intId(mixed $value): int {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        throw new ToolCallException('Block IDs must be integers.');
    }

    /**
     * Normalize a move position (an int index or before:/after: string).
     *
     * @throws ToolCallException
     */
    private static function normalizePosition(mixed $position): string {
        if (is_int($position)) {
            return (string) $position;
        }

        if (is_string($position) && trim($position) !== '') {
            return trim($position);
        }

        throw new ToolCallException('move position must be an integer index, or a before:<id> / after:<id> string.');
    }

    /**
     * Human-readable list of sibling block IDs for error messages.
     *
     * @param array<int, array{index: int, id: mixed}> $siblings
     */
    private static function siblingIdList(array $siblings): string {
        $ids = array_values(array_filter(array_map(
            static fn (array $sibling): ?string => $sibling['id'] === null ? null : (string) $sibling['id'],
            $siblings,
        )));

        return $ids === [] ? '(none)' : implode(', ', $ids);
    }
}
