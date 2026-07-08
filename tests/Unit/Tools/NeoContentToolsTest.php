<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\NeoContentTools;

describe('NeoContentTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(NeoContentTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method', function () {
        expect(method_exists(NeoContentTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(NeoContentTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has create_neo_block tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(NeoContentTools::class, 'createNeoBlock');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('create_neo_block')
            ->and($instance->description)->toContain('canonical')
            ->and($instance->description)->toContain('dryRun')
            ->and($instance->description)->toContain('children')
            ->and($instance->description)->toContain('position')
            ->and($instance->description)->toContain('parentBlockId');
    });

    it('marks create_neo_block dangerous in the content category', function () {
        $reflection = new ReflectionMethod(NeoContentTools::class, 'createNeoBlock');
        $attributes = $reflection->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });
});

describe('NeoContentTools method signatures', function () {
    it('createNeoBlock requires entryId and blockType, with optional fieldHandle, fields, children, position, parentBlockId, dryRun and context', function () {
        $reflection = new ReflectionMethod(NeoContentTools::class, 'createNeoBlock');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(9);

        expect($parameters[0]->getName())->toBe('entryId')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('int');

        expect($parameters[1]->getName())->toBe('blockType')
            ->and($parameters[1]->isOptional())->toBeFalse()
            ->and($parameters[1]->getType()?->getName())->toBe('string');

        expect($parameters[2]->getName())->toBe('fieldHandle')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('fields')
            ->and($parameters[3]->isOptional())->toBeTrue()
            ->and($parameters[3]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[4]->getName())->toBe('children')
            ->and($parameters[4]->isOptional())->toBeTrue()
            ->and($parameters[4]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[5]->getName())->toBe('position')
            ->and($parameters[5]->isOptional())->toBeTrue()
            ->and($parameters[5]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[6]->getName())->toBe('parentBlockId')
            ->and($parameters[6]->isOptional())->toBeTrue()
            ->and($parameters[6]->getType()?->getName())->toBe('int')
            ->and($parameters[6]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[7]->getName())->toBe('dryRun')
            ->and($parameters[7]->isOptional())->toBeTrue()
            ->and($parameters[7]->getType()?->getName())->toBe('bool')
            ->and($parameters[7]->getDefaultValue())->toBeFalse();

        expect($parameters[8]->getName())->toBe('context')
            ->and($parameters[8]->isOptional())->toBeTrue();
    });

    it('createNeoBlock returns array', function () {
        $reflection = new ReflectionMethod(NeoContentTools::class, 'createNeoBlock');
        expect($reflection->getReturnType()?->getName())->toBe('array');
    });
});

describe('NeoContentTools tool count', function () {
    it('has exactly 1 public method with McpTool attribute', function () {
        $reflection = new ReflectionClass(NeoContentTools::class);
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

describe('NeoContentTools availability without Neo', function () {
    it('references Neo plugin classes that are absent in this test environment', function () {
        // Neo (benf/craft-neo) is intentionally not a dependency of this plugin.
        expect(class_exists('benf\neo\elements\Block'))->toBeFalse();
    });

    it('reports unavailable without touching Craft when Neo classes are absent', function () {
        // class_exists guard short-circuits before Craft::$app is accessed,
        // so this must not fatal even though Craft is not booted in tests.
        expect(NeoContentTools::isAvailable())->toBeFalse();
    });
});
