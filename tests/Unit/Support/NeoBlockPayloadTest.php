<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\support\NeoBlockPayload;

describe('NeoBlockPayload::decode()', function () {
    it('decodes a JSON object into a handle => value map', function () {
        $result = NeoBlockPayload::decode('{"heading": "Hello", "columns": 3, "featured": true}');

        expect($result)->toBe([
            'heading' => 'Hello',
            'columns' => 3,
            'featured' => true,
        ]);
    });

    it('returns an empty array for null', function () {
        expect(NeoBlockPayload::decode(null))->toBe([]);
    });

    it('returns an empty array for blank strings', function () {
        expect(NeoBlockPayload::decode('  '))->toBe([]);
    });

    it('returns an empty array for an empty JSON object', function () {
        expect(NeoBlockPayload::decode('{}'))->toBe([]);
    });

    it('throws on invalid JSON', function () {
        NeoBlockPayload::decode('{not json');
    })->throws(ToolCallException::class, 'Invalid JSON');

    it('throws on JSON arrays (must be an object)', function () {
        NeoBlockPayload::decode('["heading", "Hello"]');
    })->throws(ToolCallException::class, 'JSON object');

    it('throws on JSON scalars', function () {
        NeoBlockPayload::decode('"heading"');
    })->throws(ToolCallException::class, 'JSON object');

    it('preserves nested values for relation and complex fields', function () {
        $result = NeoBlockPayload::decode('{"images": [12, 34], "settings": {"align": "left"}}');

        expect($result['images'])->toBe([12, 34])
            ->and($result['settings'])->toBe(['align' => 'left']);
    });
});

describe('NeoBlockPayload::unknownHandles()', function () {
    it('returns handles missing from the allowed set', function () {
        $unknown = NeoBlockPayload::unknownHandles(
            ['heading' => 'Hi', 'bogus' => 1, 'other' => 2],
            ['heading', 'body'],
        );

        expect($unknown)->toBe(['bogus', 'other']);
    });

    it('returns empty when all handles are allowed', function () {
        $unknown = NeoBlockPayload::unknownHandles(
            ['heading' => 'Hi'],
            ['heading', 'body'],
        );

        expect($unknown)->toBe([]);
    });

    it('returns empty for an empty payload', function () {
        expect(NeoBlockPayload::unknownHandles([], []))->toBe([]);
    });
});

describe('NeoBlockPayload::assertKnownHandles()', function () {
    it('passes silently when all handles are known', function () {
        NeoBlockPayload::assertKnownHandles(['heading' => 'Hi'], ['heading'], 'callout');

        expect(true)->toBeTrue();
    });

    it('throws listing unknown and available handles', function () {
        try {
            NeoBlockPayload::assertKnownHandles(
                ['bogus' => 1],
                ['heading', 'body'],
                'callout',
            );

            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain("block type 'callout'")
                ->and($e->getMessage())->toContain('bogus')
                ->and($e->getMessage())->toContain('heading, body');
        }
    });

    it('reports (none) when the block type has no fields', function () {
        try {
            NeoBlockPayload::assertKnownHandles(['bogus' => 1], [], 'divider');

            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('(none)');
        }
    });
});

describe('NeoBlockPayload::summarizeBlock()', function () {
    it('summarizes id, type handle, level and enabled', function () {
        $block = new class () {
            public int $id = 42;

            public int $level = 1;

            public bool $enabled = true;

            public function getType(): object {
                return new class () {
                    public string $handle = 'callout';
                };
            }
        };

        expect(NeoBlockPayload::summarizeBlock($block))->toBe([
            'id' => 42,
            'type' => 'callout',
            'level' => 1,
            'enabled' => true,
        ]);
    });

    it('yields null type when getType is missing or throws', function () {
        $bare = new class () {
            public int $id = 7;
        };
        $throwing = new class () {
            public int $id = 8;

            public function getType(): object {
                throw new RuntimeException('Invalid block type ID');
            }
        };

        expect(NeoBlockPayload::summarizeBlock($bare)['type'])->toBeNull()
            ->and(NeoBlockPayload::summarizeBlock($throwing)['type'])->toBeNull();
    });
});

describe('NeoBlockPayload::diff()', function () {
    it('builds a before/after diff with the appended block last', function () {
        $current = [
            ['id' => 1, 'type' => 'headerBuilder', 'level' => 1, 'enabled' => true],
            ['id' => 2, 'type' => 'callout', 'level' => 1, 'enabled' => true],
        ];
        $appended = ['id' => null, 'type' => 'gallery', 'level' => 1, 'sortOrder' => 3, 'fields' => ['heading' => 'Hi']];

        $diff = NeoBlockPayload::diff($current, $appended);

        expect($diff['before']['blockCount'])->toBe(2)
            ->and($diff['before']['blocks'])->toBe($current)
            ->and($diff['after']['blockCount'])->toBe(3)
            ->and($diff['after']['blocks'][2])->toBe($appended)
            ->and($diff['appended'])->toBe($appended);
    });

    it('handles an empty builder', function () {
        $appended = ['id' => null, 'type' => 'callout', 'level' => 1, 'sortOrder' => 1, 'fields' => []];

        $diff = NeoBlockPayload::diff([], $appended);

        expect($diff['before']['blockCount'])->toBe(0)
            ->and($diff['before']['blocks'])->toBe([])
            ->and($diff['after']['blockCount'])->toBe(1)
            ->and($diff['after']['blocks'])->toBe([$appended]);
    });

    it('does not mutate the current block list', function () {
        $current = [['id' => 1, 'type' => 'callout', 'level' => 1, 'enabled' => true]];
        $copy = $current;

        NeoBlockPayload::diff($current, ['id' => null, 'type' => 'gallery']);

        expect($current)->toBe($copy);
    });
});
