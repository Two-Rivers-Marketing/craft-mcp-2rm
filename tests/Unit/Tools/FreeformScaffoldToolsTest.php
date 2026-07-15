<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\FreeformScaffoldTools;

describe('FreeformScaffoldTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(FreeformScaffoldTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method returning bool', function () {
        expect(method_exists(FreeformScaffoldTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has create_form tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'createForm');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('create_form')
            ->and($instance->description)->toContain('single-page')
            ->and($instance->description)->toContain('dryRun')
            ->and($instance->description)->toContain('dropdown')
            ->and($instance->description)->toContain('toHandle')
            ->and($instance->description)->toContain('notifications');
    });

    it('marks create_form dangerous in the content category', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'createForm');
        $attributes = $reflection->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });
});

describe('FreeformScaffoldTools method signatures', function () {
    it('createForm requires name and fields, with optional handle, dryRun and context', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'createForm');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(5);

        expect($parameters[0]->getName())->toBe('name')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('fields')
            ->and($parameters[1]->isOptional())->toBeFalse()
            ->and($parameters[1]->getType()?->getName())->toBe('string');

        expect($parameters[2]->getName())->toBe('handle')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('dryRun')
            ->and($parameters[3]->isOptional())->toBeTrue()
            ->and($parameters[3]->getType()?->getName())->toBe('bool')
            ->and($parameters[3]->getDefaultValue())->toBeFalse();

        expect($parameters[4]->getName())->toBe('context')
            ->and($parameters[4]->isOptional())->toBeTrue();
    });

    it('createForm returns array', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'createForm');
        expect($reflection->getReturnType()?->getName())->toBe('array');
    });
});

describe('FreeformScaffoldTools tool count', function () {
    it('has exactly 2 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(FreeformScaffoldTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            if ($method->getName() === 'isAvailable') {
                return false;
            }

            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(2);
    });
});

describe('FreeformScaffoldTools update_form tool', function () {
    it('has update_form tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'updateForm');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('update_form')
            ->and($instance->description)->toContain('single-page')
            ->and($instance->description)->toContain('dryRun')
            ->and($instance->description)->toContain('KEEPS')
            ->and($instance->description)->toContain('ADDS')
            ->and($instance->description)->toContain('REMOVED')
            ->and($instance->description)->toContain('outside the v1 subset');
    });

    it('marks update_form dangerous in the content category', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'updateForm');
        $attributes = $reflection->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });

    it('updateForm requires fields, with optional handle, id, dryRun and context', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'updateForm');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(5);

        expect($parameters[0]->getName())->toBe('fields')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('handle')
            ->and($parameters[1]->isOptional())->toBeTrue()
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[2]->getName())->toBe('id')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('dryRun')
            ->and($parameters[3]->isOptional())->toBeTrue()
            ->and($parameters[3]->getType()?->getName())->toBe('bool')
            ->and($parameters[3]->getDefaultValue())->toBeFalse();

        expect($parameters[4]->getName())->toBe('context')
            ->and($parameters[4]->isOptional())->toBeTrue();
    });

    it('updateForm returns array', function () {
        $reflection = new ReflectionMethod(FreeformScaffoldTools::class, 'updateForm');
        expect($reflection->getReturnType()?->getName())->toBe('array');
    });
});

describe('FreeformScaffoldTools availability without Freeform', function () {
    it('references Freeform plugin classes that are absent in this test environment', function () {
        expect(class_exists('Solspace\Freeform\Freeform'))->toBeFalse();
    });

    it('reports unavailable without touching Craft when Freeform classes are absent', function () {
        // class_exists guard short-circuits before Craft::$app is accessed,
        // so this must not fatal even though Craft is not booted in tests.
        expect(FreeformScaffoldTools::isAvailable())->toBeFalse();
    });
});
