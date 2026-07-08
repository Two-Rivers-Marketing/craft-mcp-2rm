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
     * Neo stores childBlocks as `'*'`/true (any), a list of allowed handles,
     * or false/empty (none). An unreadable rule (null) is treated leniently as
     * "allowed" so unknowns never block a legitimate tree — only an explicit
     * false or empty list is a hard "no children".
     */
    public static function parentAllowsChildren(mixed $childBlocks): bool {
        if ($childBlocks === false) {
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
     * A list of handles is checked for membership; anything else that is not
     * an explicit false (true, '*', or an unreadable null) permits the type.
     */
    public static function childBlocksAllows(mixed $childBlocks, string $childType): bool {
        if (is_array($childBlocks)) {
            return in_array($childType, $childBlocks, true);
        }

        return $childBlocks !== false;
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
