<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\NeoSerializer;

/**
 * Named stub whose class name ends in "Lightswitch" so NeoSerializer
 * detects it the same way it detects craft\fields\Lightswitch.
 */
class NeoSerializerTestLightswitch {
    public string $handle = 'featured';

    public string $name = 'Featured';

    public ?string $instructions = null;

    public string $onLabel = 'Yes';

    public string $offLabel = 'No';

    public bool $default = true;
}

function makeNeoTestField(array $props = []): object {
    return new #[AllowDynamicProperties] class ($props) {
        public function __construct(array $props) {
            foreach ($props as $name => $value) {
                $this->$name = $value;
            }
        }
    };
}

describe('NeoSerializer::fieldOptions()', function () {
    it('serializes options arrays with value, label and default', function () {
        $field = makeNeoTestField([
            'options' => [
                ['label' => 'Small', 'value' => 'sm', 'default' => ''],
                ['label' => 'Large', 'value' => 'lg', 'default' => '1'],
            ],
        ]);

        expect(NeoSerializer::fieldOptions($field))->toBe([
            ['value' => 'sm', 'label' => 'Small', 'default' => false],
            ['value' => 'lg', 'label' => 'Large', 'default' => true],
        ]);
    });

    it('skips optgroup headers', function () {
        $field = makeNeoTestField([
            'options' => [
                ['optgroup' => 'Sizes'],
                ['label' => 'Small', 'value' => 'sm'],
            ],
        ]);

        $options = NeoSerializer::fieldOptions($field);

        expect($options)->toHaveCount(1)
            ->and($options[0]['value'])->toBe('sm');
    });

    it('normalizes bare string options', function () {
        $field = makeNeoTestField(['options' => ['red', 'blue']]);

        expect(NeoSerializer::fieldOptions($field))->toBe([
            ['value' => 'red', 'label' => 'red', 'default' => false],
            ['value' => 'blue', 'label' => 'blue', 'default' => false],
        ]);
    });

    it('returns null for fields without an options property', function () {
        $field = makeNeoTestField(['handle' => 'plainText']);

        expect(NeoSerializer::fieldOptions($field))->toBeNull();
    });

    it('returns true/false values with on/off labels for lightswitch fields', function () {
        $options = NeoSerializer::fieldOptions(new NeoSerializerTestLightswitch());

        expect($options)->toBe([
            ['value' => true, 'label' => 'Yes', 'default' => true],
            ['value' => false, 'label' => 'No', 'default' => false],
        ]);
    });
});

describe('NeoSerializer::field()', function () {
    it('serializes handle, name, type, required and instructions', function () {
        $field = makeNeoTestField([
            'handle' => 'heading',
            'name' => 'Heading',
            'instructions' => 'Keep it short',
        ]);

        $result = NeoSerializer::field($field, true);

        expect($result['handle'])->toBe('heading')
            ->and($result['name'])->toBe('Heading')
            ->and($result['required'])->toBeTrue()
            ->and($result['instructions'])->toBe('Keep it short')
            ->and($result)->not->toHaveKey('options');
    });

    it('includes options for option-bearing fields', function () {
        $field = makeNeoTestField([
            'handle' => 'alignment',
            'name' => 'Alignment',
            'options' => [['label' => 'Left', 'value' => 'left']],
        ]);

        $result = NeoSerializer::field($field);

        expect($result['required'])->toBeFalse()
            ->and($result['options'])->toBe([
                ['value' => 'left', 'label' => 'Left', 'default' => false],
            ]);
    });
});

describe('NeoSerializer::fieldLayoutFields()', function () {
    it('returns empty array for null layout', function () {
        expect(NeoSerializer::fieldLayoutFields(null))->toBe([]);
    });

    it('reads required flags from custom field layout elements', function () {
        $layout = new class () {
            public function getCustomFieldElements(): array {
                return [
                    new class () {
                        public bool $required = true;

                        public function getField(): object {
                            return makeNeoTestField(['handle' => 'heading', 'name' => 'Heading']);
                        }
                    },
                    new class () {
                        public bool $required = false;

                        public function getField(): object {
                            return makeNeoTestField(['handle' => 'body', 'name' => 'Body']);
                        }
                    },
                ];
            }
        };

        $fields = NeoSerializer::fieldLayoutFields($layout);

        expect($fields)->toHaveCount(2)
            ->and($fields[0]['handle'])->toBe('heading')
            ->and($fields[0]['required'])->toBeTrue()
            ->and($fields[1]['handle'])->toBe('body')
            ->and($fields[1]['required'])->toBeFalse();
    });

    it('falls back to getCustomFields without required flags', function () {
        $layout = new class () {
            public function getCustomFields(): array {
                return [makeNeoTestField(['handle' => 'body', 'name' => 'Body'])];
            }
        };

        $fields = NeoSerializer::fieldLayoutFields($layout);

        expect($fields)->toHaveCount(1)
            ->and($fields[0]['required'])->toBeFalse();
    });
});

describe('NeoSerializer::nesting()', function () {
    it('extracts nesting rules from a block type', function () {
        $blockType = makeNeoTestField([
            'topLevel' => true,
            'childBlocks' => ['columnItem'],
            'maxBlocks' => 5,
            'maxChildBlocks' => 4,
        ]);

        $nesting = NeoSerializer::nesting($blockType);

        expect($nesting['topLevel'])->toBeTrue()
            ->and($nesting['childBlocks'])->toBe(['columnItem'])
            ->and($nesting['maxBlocks'])->toBe(5)
            ->and($nesting['maxChildBlocks'])->toBe(4)
            ->and($nesting['minChildBlocks'])->toBeNull();
    });
});

describe('NeoSerializer::blockType()', function () {
    it('serializes handle, name, description, nesting, fields and template info', function () {
        $blockType = new class () {
            public string $handle = 'callout';

            public string $name = 'Callout';

            public ?string $description = 'A highlighted callout section';

            public bool $enabled = true;

            public bool $topLevel = true;

            public ?array $childBlocks = null;

            public function getFieldLayout(): object {
                return new class () {
                    public function getCustomFieldElements(): array {
                        return [
                            new class () {
                                public bool $required = false;

                                public function getField(): object {
                                    return makeNeoTestField(['handle' => 'extraClasses', 'name' => 'Extra Classes']);
                                }
                            },
                        ];
                    }
                };
            }
        };

        $result = NeoSerializer::blockType($blockType, true, 'body_blocks/callout.twig');

        expect($result['handle'])->toBe('callout')
            ->and($result['name'])->toBe('Callout')
            ->and($result['description'])->toBe('A highlighted callout section')
            ->and($result['enabled'])->toBeTrue()
            ->and($result['nesting']['topLevel'])->toBeTrue()
            ->and($result['fields'])->toHaveCount(1)
            ->and($result['fields'][0]['handle'])->toBe('extraClasses')
            ->and($result['template'])->toBe(['path' => 'body_blocks/callout.twig', 'exists' => true]);
    });

    it('omits template info when no path is given', function () {
        $blockType = makeNeoTestField(['handle' => 'callout', 'name' => 'Callout']);

        $result = NeoSerializer::blockType($blockType);

        expect($result)->not->toHaveKey('template')
            ->and($result['fields'])->toBe([]);
    });

    it('handles block types missing newer Neo properties gracefully', function () {
        // Older Neo versions lack description/minSiblingBlocks etc.
        $blockType = makeNeoTestField(['handle' => 'legacy', 'name' => 'Legacy']);

        $result = NeoSerializer::blockType($blockType);

        expect($result['description'])->toBeNull()
            ->and($result['nesting']['minSiblingBlocks'])->toBeNull()
            ->and($result['nesting']['groupChildBlockTypes'])->toBeNull();
    });
});
