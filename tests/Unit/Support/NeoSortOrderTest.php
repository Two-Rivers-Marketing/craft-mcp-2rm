<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\NeoSortOrder;

/**
 * @return array<int, object>
 */
function neoSortItems(?int ...$sortOrders): array {
    return array_map(
        static function (?int $sortOrder): object {
            $item = new #[AllowDynamicProperties] class () {};
            $item->sortOrder = $sortOrder;

            return $item;
        },
        $sortOrders,
    );
}

describe('NeoSortOrder::next', function () {
    it('returns 1 when the field has no block types and no groups', function () {
        expect(NeoSortOrder::next([], []))->toBe(1);
    });

    it('appends after the last block type when there are no groups', function () {
        expect(NeoSortOrder::next(neoSortItems(1, 2, 3), []))->toBe(4);
    });

    it('counts groups in the shared sequence when a group holds the highest slot', function () {
        // 3 block types + 2 groups: the old count-based formula gave 4, which group 4 holds.
        $blockTypes = neoSortItems(1, 3, 5);
        $groups = neoSortItems(2, 6);

        expect(NeoSortOrder::next($blockTypes, $groups))->toBe(7);
    });

    it('counts groups when a block type holds the highest slot', function () {
        expect(NeoSortOrder::next(neoSortItems(2, 9), neoSortItems(1, 4)))->toBe(10);
    });

    it('goes past gaps in the sequence instead of filling them', function () {
        expect(NeoSortOrder::next(neoSortItems(1, 7), neoSortItems(3)))->toBe(8);
    });

    it('lands after existing duplicates', function () {
        expect(NeoSortOrder::next(neoSortItems(1, 22, 24), neoSortItems(22, 24)))->toBe(25);
    });

    it('treats a null sortOrder as 0', function () {
        expect(NeoSortOrder::next(neoSortItems(null, null), neoSortItems(null)))->toBe(1)
            ->and(NeoSortOrder::next(neoSortItems(null, 2), neoSortItems(null)))->toBe(3);
    });

    it('treats a missing sortOrder property as 0', function () {
        $bare = new #[AllowDynamicProperties] class () {};

        expect(NeoSortOrder::next([$bare], []))->toBe(1)
            ->and(NeoSortOrder::next([$bare], neoSortItems(5)))->toBe(6);
    });

    it('works with groups only', function () {
        expect(NeoSortOrder::next([], neoSortItems(1, 2)))->toBe(3);
    });
});
