<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\NeoScaffoldTools;

describe('NeoScaffoldTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(NeoScaffoldTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method', function () {
        expect(method_exists(NeoScaffoldTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(NeoScaffoldTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has create_block_type tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(NeoScaffoldTools::class, 'createBlockType');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('create_block_type')
            ->and($instance->description)->toContain('dryRun')
            ->and($instance->description)->toContain('existingFields')
            ->and($instance->description)->toContain('newFields')
            ->and($instance->description)->toContain('childBlockTypes')
            ->and($instance->description)->toContain('body_blocks')
            ->and($instance->description)->toContain('NEVER overwrites');
    });

    it('marks create_block_type dangerous in the schema category', function () {
        $reflection = new ReflectionMethod(NeoScaffoldTools::class, 'createBlockType');
        $attributes = $reflection->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::SCHEMA)
            ->and($instance->dangerous)->toBeTrue();
    });
});

describe('NeoScaffoldTools method signatures', function () {
    it('createBlockType requires name, with optional handle, fieldHandle, existingFields, newFields, childBlockTypes, scaffoldTemplate, dryRun and context', function () {
        $reflection = new ReflectionMethod(NeoScaffoldTools::class, 'createBlockType');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(9);

        expect($parameters[0]->getName())->toBe('name')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('handle')
            ->and($parameters[1]->isOptional())->toBeTrue()
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[2]->getName())->toBe('fieldHandle')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('existingFields')
            ->and($parameters[3]->isOptional())->toBeTrue()
            ->and($parameters[3]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[4]->getName())->toBe('newFields')
            ->and($parameters[4]->isOptional())->toBeTrue()
            ->and($parameters[4]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[5]->getName())->toBe('childBlockTypes')
            ->and($parameters[5]->isOptional())->toBeTrue()
            ->and($parameters[5]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[6]->getName())->toBe('scaffoldTemplate')
            ->and($parameters[6]->isOptional())->toBeTrue()
            ->and($parameters[6]->getType()?->getName())->toBe('bool')
            ->and($parameters[6]->getDefaultValue())->toBeTrue();

        expect($parameters[7]->getName())->toBe('dryRun')
            ->and($parameters[7]->isOptional())->toBeTrue()
            ->and($parameters[7]->getType()?->getName())->toBe('bool')
            ->and($parameters[7]->getDefaultValue())->toBeFalse();

        expect($parameters[8]->getName())->toBe('context')
            ->and($parameters[8]->isOptional())->toBeTrue();
    });

    it('createBlockType returns array', function () {
        $reflection = new ReflectionMethod(NeoScaffoldTools::class, 'createBlockType');
        expect($reflection->getReturnType()?->getName())->toBe('array');
    });
});

describe('NeoScaffoldTools tool count', function () {
    it('has exactly 1 public method with McpTool attribute', function () {
        $reflection = new ReflectionClass(NeoScaffoldTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            if ($method->getName() === 'isAvailable') {
                return false;
            }

            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(1);
    });
});

describe('NeoScaffoldTools availability without Neo', function () {
    it('references Neo plugin classes that are absent in this test environment', function () {
        // Neo (benf/craft-neo) is intentionally not a dependency of this plugin.
        expect(class_exists('benf\neo\models\BlockType'))->toBeFalse();
    });

    it('reports unavailable without touching Craft when Neo classes are absent', function () {
        // class_exists guard short-circuits before Craft::$app is accessed,
        // so this must not fatal even though Craft is not booted in tests.
        expect(NeoScaffoldTools::isAvailable())->toBeFalse();
    });
});
