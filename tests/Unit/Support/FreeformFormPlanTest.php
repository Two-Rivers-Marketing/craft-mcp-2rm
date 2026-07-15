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
