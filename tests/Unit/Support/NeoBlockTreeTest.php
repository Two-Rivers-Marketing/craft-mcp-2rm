<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\support\NeoBlockTree;

/**
 * Shared flat-summary fixture mirroring how Neo stores blocks: a preorder
 * list where descendants immediately follow their parent at a higher level.
 *
 *   idx0  id1  multiColumn  L1
 *   idx1  id2  columnItem   L2   (child of 1)
 *   idx2  id3  columnItem   L2   (child of 1)
 *   idx3  id4  callout      L1
 *
 * @return array<int, array<string, mixed>>
 */
function treeFixture(): array {
    return [
        ['id' => 1, 'type' => 'multiColumn', 'level' => 1, 'enabled' => true],
        ['id' => 2, 'type' => 'columnItem', 'level' => 2, 'enabled' => true],
        ['id' => 3, 'type' => 'columnItem', 'level' => 2, 'enabled' => true],
        ['id' => 4, 'type' => 'callout', 'level' => 1, 'enabled' => true],
    ];
}

describe('NeoBlockTree::decodeChildren()', function () {
    it('returns an empty array for null, blank and empty list', function () {
        expect(NeoBlockTree::decodeChildren(null))->toBe([])
            ->and(NeoBlockTree::decodeChildren('  '))->toBe([])
            ->and(NeoBlockTree::decodeChildren('[]'))->toBe([]);
    });

    it('decodes a JSON list of node payloads', function () {
        $result = NeoBlockTree::decodeChildren('[{"blockType":"columnItem"},{"blockType":"callout"}]');

        expect($result)->toHaveCount(2)
            ->and($result[0]['blockType'])->toBe('columnItem')
            ->and($result[1]['blockType'])->toBe('callout');
    });

    it('throws on invalid JSON', function () {
        NeoBlockTree::decodeChildren('[not json');
    })->throws(ToolCallException::class, 'Invalid JSON');

    it('throws when the top level is an object, not a list', function () {
        NeoBlockTree::decodeChildren('{"blockType":"columnItem"}');
    })->throws(ToolCallException::class, 'JSON array');

    it('throws on scalars', function () {
        NeoBlockTree::decodeChildren('42');
    })->throws(ToolCallException::class, 'JSON array');
});

describe('NeoBlockTree::normalizeTree()', function () {
    it('normalizes a flat root with no children', function () {
        $tree = NeoBlockTree::normalizeTree('callout', ['heading' => 'Hi'], []);

        expect($tree)->toBe([
            'blockType' => 'callout',
            'fields' => ['heading' => 'Hi'],
            'children' => [],
        ]);
    });

    it('normalizes a nested tree recursively', function () {
        $tree = NeoBlockTree::normalizeTree('multiColumn', [], [
            ['blockType' => 'columnItem', 'fields' => ['heading' => 'A']],
            ['blockType' => 'columnItem', 'children' => [
                ['blockType' => 'callout', 'fields' => ['body' => 'deep']],
            ]],
        ]);

        expect($tree['children'])->toHaveCount(2)
            ->and($tree['children'][0]['blockType'])->toBe('columnItem')
            ->and($tree['children'][0]['fields'])->toBe(['heading' => 'A'])
            ->and($tree['children'][0]['children'])->toBe([])
            ->and($tree['children'][1]['children'][0]['blockType'])->toBe('callout')
            ->and($tree['children'][1]['children'][0]['fields'])->toBe(['body' => 'deep']);
    });

    it('throws when a child node is not an object', function () {
        NeoBlockTree::normalizeTree('multiColumn', [], ['just a string']);
    })->throws(ToolCallException::class, 'block.children[0]');

    it('throws when blockType is missing', function () {
        NeoBlockTree::normalizeTree('multiColumn', [], [['fields' => ['x' => 1]]]);
    })->throws(ToolCallException::class, "'blockType' must be a non-empty string");

    it('throws when blockType is blank', function () {
        NeoBlockTree::normalizeTree('multiColumn', [], [['blockType' => '   ']]);
    })->throws(ToolCallException::class, "'blockType' must be a non-empty string");

    it('throws when fields is a list, not an object', function () {
        NeoBlockTree::normalizeTree('multiColumn', [], [['blockType' => 'columnItem', 'fields' => [1, 2]]]);
    })->throws(ToolCallException::class, "'fields' must be an object");

    it('throws when children is an object, not a list', function () {
        NeoBlockTree::normalizeTree('multiColumn', [], [
            ['blockType' => 'columnItem', 'children' => ['blockType' => 'callout']],
        ]);
    })->throws(ToolCallException::class, "'children' must be an array");

    it('reports the deep path of a malformed grandchild', function () {
        try {
            NeoBlockTree::normalizeTree('multiColumn', [], [
                ['blockType' => 'columnItem', 'children' => [
                    ['blockType' => 'callout', 'children' => [
                        ['fields' => ['x' => 1]],
                    ]],
                ]],
            ]);
            test()->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('block.children[0].children[0].children[0]');
        }
    });
});

describe('NeoBlockTree::flatten()', function () {
    it('flattens a single node at the given root level', function () {
        $flat = NeoBlockTree::flatten(
            ['blockType' => 'callout', 'fields' => ['a' => 1], 'children' => []],
            1,
        );

        expect($flat)->toBe([
            ['type' => 'callout', 'level' => 1, 'fields' => ['a' => 1]],
        ]);
    });

    it('flattens a nested tree in preorder with incrementing levels', function () {
        $tree = NeoBlockTree::normalizeTree('multiColumn', [], [
            ['blockType' => 'columnItem', 'children' => [
                ['blockType' => 'callout'],
            ]],
            ['blockType' => 'columnItem'],
        ]);

        $flat = NeoBlockTree::flatten($tree, 1);

        expect(array_map(fn ($i) => [$i['type'], $i['level']], $flat))->toBe([
            ['multiColumn', 1],
            ['columnItem', 2],
            ['callout', 3],
            ['columnItem', 2],
        ]);
    });

    it('honors a non-1 root level (nesting into a parent)', function () {
        $tree = NeoBlockTree::normalizeTree('columnItem', [], [
            ['blockType' => 'callout'],
        ]);

        $flat = NeoBlockTree::flatten($tree, 2);

        expect($flat[0]['level'])->toBe(2)
            ->and($flat[1]['level'])->toBe(3);
    });
});

describe('NeoBlockTree::subtreeEnd()', function () {
    it('returns the index past a subtree with children', function () {
        expect(NeoBlockTree::subtreeEnd(treeFixture(), 0))->toBe(3);
    });

    it('returns the next index for a leaf', function () {
        expect(NeoBlockTree::subtreeEnd(treeFixture(), 3))->toBe(4)
            ->and(NeoBlockTree::subtreeEnd(treeFixture(), 1))->toBe(2);
    });
});

describe('NeoBlockTree::findIndexById()', function () {
    it('finds a block by id', function () {
        expect(NeoBlockTree::findIndexById(treeFixture(), 3))->toBe(2);
    });

    it('returns null for a missing id', function () {
        expect(NeoBlockTree::findIndexById(treeFixture(), 99))->toBeNull();
    });
});

describe('NeoBlockTree::resolveInsertionIndex() top-level', function () {
    $flat = treeFixture();
    $end = count($flat);

    it('appends at the end when position is null or blank', function () use ($flat, $end) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, null, 0, $end, 1))->toBe(4)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, '  ', 0, $end, 1))->toBe(4);
    });

    it('resolves integer indices among top-level siblings', function () use ($flat, $end) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, '0', 0, $end, 1))->toBe(0)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, '1', 0, $end, 1))->toBe(3)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, '2', 0, $end, 1))->toBe(4);
    });

    it('resolves before:<id> to the sibling start', function () use ($flat, $end) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, 'before:1', 0, $end, 1))->toBe(0)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, 'before:4', 0, $end, 1))->toBe(3);
    });

    it('resolves after:<id> past the sibling subtree', function () use ($flat, $end) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, 'after:1', 0, $end, 1))->toBe(3)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, 'after:4', 0, $end, 1))->toBe(4);
    });

    it('throws when an integer index is out of range', function () use ($flat, $end) {
        NeoBlockTree::resolveInsertionIndex($flat, '3', 0, $end, 1);
    })->throws(ToolCallException::class, 'out of range');

    it('throws when an integer index is negative', function () use ($flat, $end) {
        NeoBlockTree::resolveInsertionIndex($flat, '-1', 0, $end, 1);
    })->throws(ToolCallException::class, 'out of range');

    it('throws on a non-integer, non-reference position', function () use ($flat, $end) {
        NeoBlockTree::resolveInsertionIndex($flat, 'bogus', 0, $end, 1);
    })->throws(ToolCallException::class, 'Invalid position');

    it('throws when before:/after: references an unknown sibling', function () use ($flat, $end) {
        NeoBlockTree::resolveInsertionIndex($flat, 'before:99', 0, $end, 1);
    })->throws(ToolCallException::class, 'not an existing sibling');

    it('throws when a reference points at a nested (non-sibling) block', function () use ($flat, $end) {
        // id2 is a level-2 child, not a top-level sibling.
        NeoBlockTree::resolveInsertionIndex($flat, 'after:2', 0, $end, 1);
    })->throws(ToolCallException::class, 'not an existing sibling');

    it('throws when a reference id is non-numeric', function () use ($flat, $end) {
        NeoBlockTree::resolveInsertionIndex($flat, 'before:abc', 0, $end, 1);
    })->throws(ToolCallException::class, 'numeric block ID');
});

describe('NeoBlockTree::resolveInsertionIndex() within a parent scope', function () {
    // Parent = id1 (idx0). Its children span [1,3): id2 (idx1), id3 (idx2), level 2.
    $flat = treeFixture();

    it('appends after the last child by default', function () use ($flat) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, null, 1, 3, 2))->toBe(3);
    });

    it('resolves integer indices among the parent children', function () use ($flat) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, '0', 1, 3, 2))->toBe(1)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, '1', 1, 3, 2))->toBe(2)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, '2', 1, 3, 2))->toBe(3);
    });

    it('resolves before:/after: against the parent children', function () use ($flat) {
        expect(NeoBlockTree::resolveInsertionIndex($flat, 'before:3', 1, 3, 2))->toBe(2)
            ->and(NeoBlockTree::resolveInsertionIndex($flat, 'after:2', 1, 3, 2))->toBe(2);
    });

    it('does not see top-level siblings as valid references in a parent scope', function () use ($flat) {
        NeoBlockTree::resolveInsertionIndex($flat, 'before:4', 1, 3, 2);
    })->throws(ToolCallException::class, 'not an existing sibling');
});

describe('NeoBlockTree::parentAllowsChildren()', function () {
    it('allows for true, "*", and non-empty handle lists', function () {
        expect(NeoBlockTree::parentAllowsChildren(true))->toBeTrue()
            ->and(NeoBlockTree::parentAllowsChildren('*'))->toBeTrue()
            ->and(NeoBlockTree::parentAllowsChildren(['columnItem']))->toBeTrue();
    });

    it('disallows for explicit false and empty lists', function () {
        expect(NeoBlockTree::parentAllowsChildren(false))->toBeFalse()
            ->and(NeoBlockTree::parentAllowsChildren([]))->toBeFalse();
    });

    it('disallows for null — a Neo leaf type reports childBlocks as null', function () {
        expect(NeoBlockTree::parentAllowsChildren(null))->toBeFalse();
    });
});

describe('NeoBlockTree::childBlocksAllows()', function () {
    it('checks membership for a handle list', function () {
        expect(NeoBlockTree::childBlocksAllows(['columnItem', 'callout'], 'callout'))->toBeTrue()
            ->and(NeoBlockTree::childBlocksAllows(['columnItem'], 'callout'))->toBeFalse();
    });

    it('allows any type for "*" and true', function () {
        expect(NeoBlockTree::childBlocksAllows('*', 'anything'))->toBeTrue()
            ->and(NeoBlockTree::childBlocksAllows(true, 'anything'))->toBeTrue();
    });

    it('disallows any type for explicit false and for a leaf null', function () {
        expect(NeoBlockTree::childBlocksAllows(false, 'anything'))->toBeFalse()
            ->and(NeoBlockTree::childBlocksAllows(null, 'anything'))->toBeFalse();
    });
});

describe('NeoBlockTree::decodeOrder()', function () {
    it('decodes a JSON array of integer IDs', function () {
        expect(NeoBlockTree::decodeOrder('[4, 1]'))->toBe([4, 1]);
    });

    it('coerces numeric-string IDs to ints', function () {
        expect(NeoBlockTree::decodeOrder('["4", "1"]'))->toBe([4, 1]);
    });

    it('throws on null or blank', function () {
        NeoBlockTree::decodeOrder(null);
    })->throws(ToolCallException::class, 'order must be a JSON array');

    it('throws on an empty array', function () {
        NeoBlockTree::decodeOrder('[]');
    })->throws(ToolCallException::class, 'at least one block ID');

    it('throws on invalid JSON', function () {
        NeoBlockTree::decodeOrder('[not json');
    })->throws(ToolCallException::class, 'Invalid JSON');

    it('throws when given an object, not a list', function () {
        NeoBlockTree::decodeOrder('{"a":1}');
    })->throws(ToolCallException::class, 'JSON array');

    it('throws on non-integer IDs', function () {
        NeoBlockTree::decodeOrder('[1, "abc"]');
    })->throws(ToolCallException::class, 'must be integers');
});

describe('NeoBlockTree::decodeMove()', function () {
    it('decodes blockId with an integer position', function () {
        expect(NeoBlockTree::decodeMove('{"blockId": 4, "position": 2}'))
            ->toBe(['blockId' => 4, 'position' => '2']);
    });

    it('decodes blockId with a before:/after: position', function () {
        expect(NeoBlockTree::decodeMove('{"blockId": 4, "position": "before:1"}'))
            ->toBe(['blockId' => 4, 'position' => 'before:1']);
    });

    it('throws on null or blank', function () {
        NeoBlockTree::decodeMove('  ');
    })->throws(ToolCallException::class, 'move must be a JSON object');

    it('throws when given a list, not an object', function () {
        NeoBlockTree::decodeMove('[1, 2]');
    })->throws(ToolCallException::class, 'JSON object');

    it('throws when blockId is missing', function () {
        NeoBlockTree::decodeMove('{"position": 1}');
    })->throws(ToolCallException::class, 'requires a blockId');

    it('throws when position is missing', function () {
        NeoBlockTree::decodeMove('{"blockId": 4}');
    })->throws(ToolCallException::class, 'requires a position');

    it('throws when position is neither int nor non-empty string', function () {
        NeoBlockTree::decodeMove('{"blockId": 4, "position": null}');
    })->throws(ToolCallException::class, 'move position must be');
});

describe('NeoBlockTree::siblingScope()', function () {
    it('returns the whole list for a top-level block', function () {
        expect(NeoBlockTree::siblingScope(treeFixture(), 0))
            ->toBe(['start' => 0, 'end' => 4, 'level' => 1]);
    });

    it('returns the parent children range for a nested block', function () {
        // idx1 (id2) is a child of idx0 (id1); siblings span [1,3) at level 2.
        expect(NeoBlockTree::siblingScope(treeFixture(), 1))
            ->toBe(['start' => 1, 'end' => 3, 'level' => 2]);
    });
});

describe('NeoBlockTree::assertPermutation() via orderIndexes()', function () {
    it('reorders whole subtrees by a valid permutation', function () {
        // Move the callout (id4) before the multiColumn (id1) + its children.
        $order = NeoBlockTree::orderIndexes(treeFixture(), [4, 1]);

        expect($order)->toBe([3, 0, 1, 2]);
    });

    it('is a no-op ordering when the order matches the current order', function () {
        expect(NeoBlockTree::orderIndexes(treeFixture(), [1, 4]))->toBe([0, 1, 2, 3]);
    });

    it('throws listing missing top-level IDs', function () {
        NeoBlockTree::orderIndexes(treeFixture(), [1]);
    })->throws(ToolCallException::class, 'missing: 4');

    it('throws listing unexpected IDs (including nested/unknown)', function () {
        NeoBlockTree::orderIndexes(treeFixture(), [1, 4, 99]);
    })->throws(ToolCallException::class, 'unexpected: 99');

    it('rejects a nested block ID as a top-level entry', function () {
        // id2 is a level-2 child, never a valid top-level order entry.
        NeoBlockTree::orderIndexes(treeFixture(), [1, 2, 4]);
    })->throws(ToolCallException::class, 'unexpected: 2');

    it('reports duplicates', function () {
        NeoBlockTree::orderIndexes(treeFixture(), [1, 1]);
    })->throws(ToolCallException::class, 'missing: 4');
});

describe('NeoBlockTree::moveIndexes() top-level', function () {
    it('moves a leaf block before another via before:', function () {
        // Move callout (id4, idx3) before multiColumn (id1, idx0).
        expect(NeoBlockTree::moveIndexes(treeFixture(), 4, 'before:1'))->toBe([3, 0, 1, 2]);
    });

    it('moves a whole subtree via after:', function () {
        // Move multiColumn (id1) + its 2 children to after callout (id4).
        expect(NeoBlockTree::moveIndexes(treeFixture(), 1, 'after:4'))->toBe([3, 0, 1, 2]);
    });

    it('moves via an integer index', function () {
        // Position "0" = before the first top-level sibling.
        expect(NeoBlockTree::moveIndexes(treeFixture(), 4, '0'))->toBe([3, 0, 1, 2]);
    });

    it('is a no-op when moved to its own integer position', function () {
        expect(NeoBlockTree::moveIndexes(treeFixture(), 1, '0'))->toBe([0, 1, 2, 3]);
    });

    it('rejects a before: reference to the moved block itself', function () {
        NeoBlockTree::moveIndexes(treeFixture(), 1, 'before:1');
    })->throws(ToolCallException::class, 'itself or one of its own descendants');

    it('throws when the block does not exist', function () {
        NeoBlockTree::moveIndexes(treeFixture(), 99, '0');
    })->throws(ToolCallException::class, 'was not found among the blocks to reorder');

    it('rejects a before: reference inside the moved subtree', function () {
        // id2 is a child of id1; moving id1 relative to its own descendant is invalid.
        NeoBlockTree::moveIndexes(treeFixture(), 1, 'after:2');
    })->throws(ToolCallException::class, 'itself or one of its own descendants');
});

describe('NeoBlockTree::moveIndexes() within a parent scope', function () {
    it('reorders children inside their parent', function () {
        // Move id3 (idx2) before id2 (idx1); both are children of id1.
        expect(NeoBlockTree::moveIndexes(treeFixture(), 3, 'before:2'))->toBe([0, 2, 1, 3]);
    });

    it('does not allow a child to reference a top-level sibling', function () {
        // id4 is top-level; not a valid reference within id2's sibling scope.
        NeoBlockTree::moveIndexes(treeFixture(), 2, 'before:4');
    })->throws(ToolCallException::class, 'not an existing sibling');
});

describe('NeoBlockTree::reorderDiff()', function () {
    it('reports before/after block lists', function () {
        $before = treeFixture();
        $after = [$before[3], $before[0], $before[1], $before[2]];

        $diff = NeoBlockTree::reorderDiff($before, $after);

        expect($diff['before']['blockCount'])->toBe(4)
            ->and($diff['after']['blockCount'])->toBe(4)
            ->and($diff['after']['blocks'][0]['id'])->toBe(4)
            ->and($diff['after']['blocks'][1]['id'])->toBe(1);
    });
});

describe('NeoBlockTree::deleteDiff()', function () {
    it('removes a subtree range and reports before/after/removed', function () {
        // Remove multiColumn (idx0) + its 2 children: range [0,3).
        $diff = NeoBlockTree::deleteDiff(treeFixture(), 0, 3);

        expect($diff['before']['blockCount'])->toBe(4)
            ->and($diff['after']['blockCount'])->toBe(1)
            ->and($diff['after']['blocks'][0]['id'])->toBe(4)
            ->and($diff['removed']['blockCount'])->toBe(3)
            ->and(array_map(fn ($b) => $b['id'], $diff['removed']['blocks']))->toBe([1, 2, 3]);
    });

    it('removes a single leaf', function () {
        $diff = NeoBlockTree::deleteDiff(treeFixture(), 3, 4);

        expect($diff['after']['blockCount'])->toBe(3)
            ->and($diff['removed']['blockCount'])->toBe(1)
            ->and($diff['removed']['blocks'][0]['id'])->toBe(4);
    });
});

describe('NeoBlockTree::diff()', function () {
    it('splices a flattened subtree into the before list at the index', function () {
        $before = treeFixture();
        $inserted = [
            ['type' => 'multiColumn', 'level' => 1, 'fields' => []],
            ['type' => 'columnItem', 'level' => 2, 'fields' => ['heading' => 'A']],
            ['type' => 'columnItem', 'level' => 2, 'fields' => ['heading' => 'B']],
        ];

        // Insert after id1's subtree (index 3).
        $diff = NeoBlockTree::diff($before, 3, $inserted);

        expect($diff['before']['blockCount'])->toBe(4)
            ->and($diff['after']['blockCount'])->toBe(7)
            ->and($diff['inserted']['at'])->toBe(3)
            ->and($diff['inserted']['blockCount'])->toBe(3);

        // The inserted subtree occupies positions 3,4,5 in the after list.
        expect(array_map(fn ($b) => [$b['type'], $b['level']], $diff['after']['blocks']))->toBe([
            ['multiColumn', 1],
            ['columnItem', 2],
            ['columnItem', 2],
            ['multiColumn', 1],
            ['columnItem', 2],
            ['columnItem', 2],
            ['callout', 1],
        ]);
    });

    it('marks inserted blocks as new with a null id', function () {
        $diff = NeoBlockTree::diff([], 0, [
            ['type' => 'callout', 'level' => 1, 'fields' => []],
        ]);

        expect($diff['after']['blocks'][0]['new'])->toBeTrue()
            ->and($diff['after']['blocks'][0]['id'])->toBeNull()
            ->and($diff['inserted']['blocks'][0]['new'])->toBeTrue();
    });

    it('inserts at the front when index is 0', function () {
        $before = treeFixture();
        $diff = NeoBlockTree::diff($before, 0, [
            ['type' => 'callout', 'level' => 1, 'fields' => []],
        ]);

        expect($diff['after']['blocks'][0]['type'])->toBe('callout')
            ->and($diff['after']['blocks'][0]['new'])->toBeTrue()
            ->and($diff['after']['blocks'][1]['id'])->toBe(1);
    });

    it('does not mutate the before list', function () {
        $before = treeFixture();
        $copy = $before;

        NeoBlockTree::diff($before, 2, [['type' => 'callout', 'level' => 1, 'fields' => []]]);

        expect($before)->toBe($copy);
    });
});

describe('NeoBlockTree::newBlockIds()', function () {
    it('returns ids present after the save but not before, in document order', function () {
        $before = treeFixture();
        $after = [
            ['id' => 1, 'type' => 'multiColumn', 'level' => 1],
            ['id' => 12, 'type' => 'columnItem', 'level' => 2],
            ['id' => 2, 'type' => 'columnItem', 'level' => 2],
            ['id' => 3, 'type' => 'columnItem', 'level' => 2],
            ['id' => 4, 'type' => 'callout', 'level' => 1],
            ['id' => 10, 'type' => 'multiColumn', 'level' => 1],
            ['id' => 11, 'type' => 'columnItem', 'level' => 2],
        ];

        expect(NeoBlockTree::newBlockIds($before, $after))->toBe([12, 10, 11]);
    });

    it('returns an empty list when nothing was added', function () {
        $before = treeFixture();

        expect(NeoBlockTree::newBlockIds($before, $before))->toBe([]);
    });

    it('returns every id when there were no pre-save blocks', function () {
        $after = [
            ['id' => 7, 'type' => 'callout', 'level' => 1],
            ['id' => 8, 'type' => 'callout', 'level' => 1],
        ];

        expect(NeoBlockTree::newBlockIds([], $after))->toBe([7, 8]);
    });

    it('ignores null and missing ids on both sides', function () {
        $before = [
            ['id' => null, 'type' => 'callout', 'level' => 1],
            ['type' => 'callout', 'level' => 1],
            ['id' => 1, 'type' => 'multiColumn', 'level' => 1],
        ];
        $after = [
            ['id' => 1, 'type' => 'multiColumn', 'level' => 1],
            ['id' => null, 'type' => 'callout', 'level' => 1],
            ['id' => 9, 'type' => 'callout', 'level' => 1],
        ];

        expect(NeoBlockTree::newBlockIds($before, $after))->toBe([9]);
    });

    it('casts numeric-string ids to integers', function () {
        $before = [['id' => '1', 'type' => 'callout', 'level' => 1]];
        $after = [
            ['id' => '1', 'type' => 'callout', 'level' => 1],
            ['id' => '42', 'type' => 'callout', 'level' => 1],
        ];

        expect(NeoBlockTree::newBlockIds($before, $after))->toBe([42]);
    });
});
