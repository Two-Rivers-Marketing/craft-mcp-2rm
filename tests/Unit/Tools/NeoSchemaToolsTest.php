<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\NeoSchemaTools;

describe('NeoSchemaTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(NeoSchemaTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method', function () {
        expect(method_exists(NeoSchemaTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(NeoSchemaTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has describe_content_builder tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(NeoSchemaTools::class, 'describeContentBuilder');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('describe_content_builder')
            ->and($instance->description)->toContain('block type')
            ->and($instance->description)->toContain('nesting');
    });

    it('has get_block_type tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(NeoSchemaTools::class, 'getBlockType');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_block_type')
            ->and($instance->description)->toContain('block type');
    });

    it('categorizes both tools as schema', function () {
        foreach (['describeContentBuilder', 'getBlockType'] as $methodName) {
            $reflection = new ReflectionMethod(NeoSchemaTools::class, $methodName);
            $attributes = $reflection->getAttributes(McpToolMeta::class);

            expect($attributes)->toHaveCount(1);

            $instance = $attributes[0]->newInstance();
            expect($instance->category)->toBe(ToolCategory::SCHEMA)
                ->and($instance->dangerous)->toBeFalse();
        }
    });
});

describe('NeoSchemaTools method signatures', function () {
    it('describeContentBuilder has optional entryId, fieldHandle and context parameters', function () {
        $reflection = new ReflectionMethod(NeoSchemaTools::class, 'describeContentBuilder');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(3);

        expect($parameters[0]->getName())->toBe('entryId')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[1]->getName())->toBe('fieldHandle')
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue();

        foreach ($parameters as $param) {
            expect($param->isOptional())->toBeTrue();
        }
    });

    it('getBlockType requires a handle and accepts optional fieldHandle and context', function () {
        $reflection = new ReflectionMethod(NeoSchemaTools::class, 'getBlockType');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(3);

        expect($parameters[0]->getName())->toBe('handle')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('fieldHandle')
            ->and($parameters[1]->isOptional())->toBeTrue();

        expect($parameters[2]->isOptional())->toBeTrue();
    });

    it('all tool methods return array', function () {
        foreach (['describeContentBuilder', 'getBlockType'] as $methodName) {
            $reflection = new ReflectionMethod(NeoSchemaTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('NeoSchemaTools tool count', function () {
    it('has exactly 2 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(NeoSchemaTools::class);
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

describe('NeoSchemaTools availability without Neo', function () {
    it('references a Neo plugin class that is absent in this test environment', function () {
        // Neo (benf/craft-neo) is intentionally not a dependency of this plugin.
        // isAvailable() must guard on class_exists so the tools never fatal.
        expect(class_exists('benf\neo\Plugin'))->toBeFalse();
    });

    it('reports unavailable without touching Craft when Neo classes are absent', function () {
        // class_exists guard short-circuits before Craft::$app is accessed,
        // so this must not fatal even though Craft is not booted in tests.
        expect(NeoSchemaTools::isAvailable())->toBeFalse();
    });
});
