<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\support\FreeformFormPlan;

// NOTE: these tests stay boot-free (no Craft application). The label/name ->
// handle defaulting path delegates to craft\helpers\StringHelper::toHandle(),
// which needs a booted Craft app (it reads the app language), so every fixture
// here passes an explicit handle to keep toHandle() off the tested path. The
// defaulting itself is a trivial delegation exercised live.

describe('FreeformFormPlan::resolveFormHandle', function () {
    it('uses an explicit handle when provided', function () {
        expect(FreeformFormPlan::resolveFormHandle('myForm', 'Contact Us'))->toBe('myForm');
    });

    it('trims an explicit handle', function () {
        expect(FreeformFormPlan::resolveFormHandle('  myForm  ', 'Contact Us'))->toBe('myForm');
    });

    it('rejects an invalid explicit handle', function () {
        FreeformFormPlan::resolveFormHandle('9bad', 'Contact');
    })->throws(ToolCallException::class);
});

describe('FreeformFormPlan::assertValidHandle', function () {
    it('accepts a valid handle', function () {
        FreeformFormPlan::assertValidHandle('goodHandle_1', 'Field');
        expect(true)->toBeTrue();
    });

    it('rejects a handle starting with a digit', function () {
        FreeformFormPlan::assertValidHandle('1bad', 'Field');
    })->throws(ToolCallException::class);

    it('rejects a handle with punctuation', function () {
        FreeformFormPlan::assertValidHandle('bad-handle', 'Field');
    })->throws(ToolCallException::class);
});

describe('FreeformFormPlan::decodeFields', function () {
    it('normalizes a list of field specs', function () {
        $json = json_encode([
            ['label' => 'Full Name', 'handle' => 'fullName', 'type' => 'text', 'required' => true],
            ['label' => 'Email Address', 'handle' => 'emailAddress', 'type' => 'email'],
        ]);

        $specs = FreeformFormPlan::decodeFields($json);

        expect($specs)->toHaveCount(2)
            ->and($specs[0]['label'])->toBe('Full Name')
            ->and($specs[0]['handle'])->toBe('fullName')
            ->and($specs[0]['type'])->toBe('text')
            ->and($specs[0]['typeClass'])->toBe('Solspace\Freeform\Fields\Implementations\TextField')
            ->and($specs[0]['required'])->toBeTrue()
            ->and($specs[0]['options'])->toBe([])
            ->and($specs[1]['handle'])->toBe('emailAddress')
            ->and($specs[1]['required'])->toBeFalse()
            ->and($specs[1]['typeClass'])->toBe('Solspace\Freeform\Fields\Implementations\EmailField');
    });

    it('trims an explicit field handle', function () {
        $json = json_encode([['label' => 'Full Name', 'handle' => '  customHandle  ', 'type' => 'text']]);
        expect(FreeformFormPlan::decodeFields($json)[0]['handle'])->toBe('customHandle');
    });

    it('maps every supported v1 field type', function () {
        $json = json_encode([
            ['label' => 'A', 'handle' => 'a', 'type' => 'text'],
            ['label' => 'B', 'handle' => 'b', 'type' => 'textarea'],
            ['label' => 'C', 'handle' => 'c', 'type' => 'email'],
            ['label' => 'D', 'handle' => 'd', 'type' => 'checkbox'],
            ['label' => 'E', 'handle' => 'e', 'type' => 'number'],
            ['label' => 'F', 'handle' => 'f', 'type' => 'dropdown', 'options' => [['label' => 'One', 'value' => '1']]],
        ]);

        $types = array_column(FreeformFormPlan::decodeFields($json), 'typeClass');

        expect($types)->toBe([
            'Solspace\Freeform\Fields\Implementations\TextField',
            'Solspace\Freeform\Fields\Implementations\TextareaField',
            'Solspace\Freeform\Fields\Implementations\EmailField',
            'Solspace\Freeform\Fields\Implementations\CheckboxField',
            'Solspace\Freeform\Fields\Implementations\NumberField',
            'Solspace\Freeform\Fields\Implementations\DropdownField',
        ]);
    });

    it('normalizes dropdown options into a label/value list', function () {
        $json = json_encode([[
            'label' => 'Subject',
            'handle' => 'subject',
            'type' => 'dropdown',
            'options' => [['label' => 'General', 'value' => 'gen'], ['label' => 'Sales', 'value' => 'sales']],
        ]]);

        expect(FreeformFormPlan::decodeFields($json)[0]['options'])->toBe([
            ['label' => 'General', 'value' => 'gen'],
            ['label' => 'Sales', 'value' => 'sales'],
        ]);
    });

    it('is case-insensitive on the type keyword', function () {
        $json = json_encode([['label' => 'A', 'handle' => 'a', 'type' => 'Text']]);
        expect(FreeformFormPlan::decodeFields($json)[0]['type'])->toBe('text');
    });

    it('rejects empty JSON', function () {
        FreeformFormPlan::decodeFields('   ');
    })->throws(ToolCallException::class);

    it('rejects a non-list JSON payload', function () {
        FreeformFormPlan::decodeFields('{"label":"x"}');
    })->throws(ToolCallException::class);

    it('rejects an empty fields array', function () {
        FreeformFormPlan::decodeFields('[]');
    })->throws(ToolCallException::class);

    it('rejects a field without a label', function () {
        FreeformFormPlan::decodeFields(json_encode([['handle' => 'x', 'type' => 'text']]));
    })->throws(ToolCallException::class);

    it('rejects a field with an unknown type', function () {
        FreeformFormPlan::decodeFields(json_encode([['label' => 'X', 'handle' => 'x', 'type' => 'wysiwyg']]));
    })->throws(ToolCallException::class);

    it('rejects a dropdown without options', function () {
        FreeformFormPlan::decodeFields(json_encode([['label' => 'X', 'handle' => 'x', 'type' => 'dropdown']]));
    })->throws(ToolCallException::class);

    it('rejects a dropdown option missing value', function () {
        FreeformFormPlan::decodeFields(json_encode([
            ['label' => 'X', 'handle' => 'x', 'type' => 'dropdown', 'options' => [['label' => 'One']]],
        ]));
    })->throws(ToolCallException::class);
});

describe('FreeformFormPlan::resolveExistingType', function () {
    it('maps a known typeClass back to its v1 keyword', function () {
        expect(FreeformFormPlan::resolveExistingType('Solspace\Freeform\Fields\Implementations\TextField'))->toBe('text')
            ->and(FreeformFormPlan::resolveExistingType('Solspace\Freeform\Fields\Implementations\DropdownField'))->toBe('dropdown');
    });

    it('returns null for a typeClass outside the v1 subset', function () {
        expect(FreeformFormPlan::resolveExistingType('Solspace\Freeform\Fields\Implementations\FileUploadField'))->toBeNull();
    });
});

describe('FreeformFormPlan::planFieldChanges', function () {
    function ffField(string $handle, string $uid, string $rowUid, int $rowOrder = 0, int $fieldOrder = 0, string $typeClass = 'Solspace\Freeform\Fields\Implementations\TextField', bool $supported = true): array {
        return [
            'handle' => $handle,
            'uid' => $uid,
            'rowUid' => $rowUid,
            'rowOrder' => $rowOrder,
            'fieldOrder' => $fieldOrder,
            'typeClass' => $typeClass,
            'metadata' => ['handle' => $handle, 'label' => ucfirst($handle), 'required' => false],
            'supported' => $supported,
        ];
    }

    function ffSpec(string $handle, string $type = 'text'): array {
        return FreeformFormPlan::decodeFields(json_encode([['label' => ucfirst($handle), 'handle' => $handle, 'type' => $type]]))[0];
    }

    it('treats every spec as new when the form has no existing fields', function () {
        $plan = FreeformFormPlan::planFieldChanges([], [ffSpec('a'), ffSpec('b')]);

        expect($plan['managed'])->toHaveCount(2)
            ->and($plan['managed'][0]['isNew'])->toBeTrue()
            ->and($plan['managed'][0]['rowOrder'])->toBe(0)
            ->and($plan['managed'][0]['fieldOrder'])->toBe(0)
            ->and($plan['managed'][1]['isNew'])->toBeTrue()
            ->and($plan['managed'][1]['rowOrder'])->toBe(1)
            ->and($plan['removed'])->toBe([])
            ->and($plan['preserved'])->toBe([])
            ->and($plan['conflicts'])->toBe([]);
    });

    it('reuses uid and rowUid for a kept field matched by handle', function () {
        $existing = [ffField('a', 'uid-a', 'row-a')];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a')]);

        expect($plan['managed'][0]['isNew'])->toBeFalse()
            ->and($plan['managed'][0]['existingUid'])->toBe('uid-a')
            ->and($plan['managed'][0]['existingRowUid'])->toBe('row-a')
            ->and($plan['managed'][0]['rowKey'])->toBe('row-a');
    });

    it('reorders kept fields by their new position in the spec list', function () {
        $existing = [ffField('a', 'uid-a', 'row-a'), ffField('b', 'uid-b', 'row-b')];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('b'), ffSpec('a')]);

        expect($plan['managed'][0]['spec']['handle'])->toBe('b')
            ->and($plan['managed'][0]['rowOrder'])->toBe(0)
            ->and($plan['managed'][1]['spec']['handle'])->toBe('a')
            ->and($plan['managed'][1]['rowOrder'])->toBe(1)
            ->and($plan['removed'])->toBe([]);
    });

    it('adds a field with a new handle alongside a kept field', function () {
        $existing = [ffField('a', 'uid-a', 'row-a')];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a'), ffSpec('b')]);

        expect($plan['managed'][0]['isNew'])->toBeFalse()
            ->and($plan['managed'][1]['isNew'])->toBeTrue()
            ->and($plan['managed'][1]['rowOrder'])->toBe(1)
            ->and($plan['removed'])->toBe([]);
    });

    it('marks a supported existing field omitted from specs as removed', function () {
        $existing = [ffField('a', 'uid-a', 'row-a'), ffField('b', 'uid-b', 'row-b')];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a')]);

        expect($plan['managed'])->toHaveCount(1)
            ->and($plan['removed'])->toBe([['handle' => 'b', 'uid' => 'uid-b']]);
    });

    it('keeps fields sharing an existing row grouped together with sequential in-row order', function () {
        $existing = [
            ffField('a', 'uid-a', 'row-shared', rowOrder: 0, fieldOrder: 0),
            ffField('b', 'uid-b', 'row-shared', rowOrder: 0, fieldOrder: 1),
        ];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a'), ffSpec('b')]);

        expect($plan['managed'][0]['rowKey'])->toBe('row-shared')
            ->and($plan['managed'][1]['rowKey'])->toBe('row-shared')
            ->and($plan['managed'][0]['rowOrder'])->toBe(0)
            ->and($plan['managed'][1]['rowOrder'])->toBe(0)
            ->and($plan['managed'][0]['fieldOrder'])->toBe(0)
            ->and($plan['managed'][1]['fieldOrder'])->toBe(1);
    });

    it('never removes an unsupported-type field and preserves it after the managed rows', function () {
        $existing = [
            ffField('a', 'uid-a', 'row-a', rowOrder: 0),
            ffField('sig', 'uid-sig', 'row-sig', rowOrder: 1, typeClass: 'Solspace\Freeform\Fields\Implementations\SignatureField', supported: false),
        ];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a')]);

        expect($plan['removed'])->toBe([])
            ->and($plan['preserved'])->toHaveCount(1)
            ->and($plan['preserved'][0]['handle'])->toBe('sig')
            ->and($plan['preserved'][0]['uid'])->toBe('uid-sig')
            ->and($plan['preserved'][0]['rowOrder'])->toBe(1); // offset past the 1 managed row (order 0)
    });

    it('flags a conflict when a kept field shares a row with an unsupported preserved field', function () {
        $existing = [
            ffField('a', 'uid-a', 'row-shared', rowOrder: 0, fieldOrder: 0),
            ffField('sig', 'uid-sig', 'row-shared', rowOrder: 0, fieldOrder: 1, typeClass: 'Solspace\Freeform\Fields\Implementations\SignatureField', supported: false),
        ];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a')]);

        expect($plan['conflicts'])->toBe(['row-shared']);
    });

    it('does not flag a conflict when the preserved field is unrelated to any kept field row', function () {
        $existing = [
            ffField('a', 'uid-a', 'row-a', rowOrder: 0),
            ffField('sig', 'uid-sig', 'row-sig', rowOrder: 1, typeClass: 'Solspace\Freeform\Fields\Implementations\SignatureField', supported: false),
        ];
        $plan = FreeformFormPlan::planFieldChanges($existing, [ffSpec('a')]);

        expect($plan['conflicts'])->toBe([]);
    });
});

describe('FreeformFormPlan::optionConfiguration', function () {
    it('builds a custom-source option configuration', function () {
        $config = FreeformFormPlan::optionConfiguration([
            ['label' => 'One', 'value' => '1'],
            ['label' => 'Two', 'value' => '2'],
        ]);

        expect($config['source'])->toBe('custom')
            ->and($config['useCustomValues'])->toBeTrue()
            ->and($config['options'])->toBe([
                ['label' => 'One', 'value' => '1', 'optgroup' => false],
                ['label' => 'Two', 'value' => '2', 'optgroup' => false],
            ]);
    });
});
