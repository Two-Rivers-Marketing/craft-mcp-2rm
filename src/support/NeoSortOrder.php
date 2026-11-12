<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

/**
 * Computes the next free sortOrder slot on a Neo field.
 *
 * Neo block types and block-type groups share one sort sequence per field, and
 * Neo writes `neo.orders.<fieldUid>[sortOrder - 1] = uid`. Two items with the
 * same sortOrder make one silently overwrite the other in project config, so a
 * new item must land after the highest slot held by any block type or group,
 * not after the block type count.
 *
 * Duck-typed over the models' public `sortOrder` property so it needs no Neo
 * classes. A null or missing sortOrder counts as 0.
 *
 * @author 2RM
 */
final class NeoSortOrder {
    /**
     * @param iterable<object> $blockTypes The field's block types
     * @param iterable<object> $groups The field's block-type groups
     * @return int max(sortOrder across both lists) + 1; 1 when both are empty
     */
    public static function next(iterable $blockTypes, iterable $groups): int {
        return max(self::highest($blockTypes), self::highest($groups)) + 1;
    }

    /**
     * @param iterable<object> $items
     */
    private static function highest(iterable $items): int {
        $highest = 0;

        foreach ($items as $item) {
            $highest = max($highest, (int) ($item->sortOrder ?? 0));
        }

        return $highest;
    }
}
