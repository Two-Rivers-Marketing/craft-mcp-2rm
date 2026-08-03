<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\GlobalSetTools;

describe('GlobalSetTools get_global_set', function () {
    it('exposes get_global_set with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(GlobalSetTools::class, 'getGlobalSet'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe('get_global_set');
    });

    it('is not dangerous and sits in the CONTENT category', function () {
        $meta = (new ReflectionMethod(GlobalSetTools::class, 'getGlobalSet'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeFalse()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires handle, with an optional context', function () {
        $params = (new ReflectionMethod(GlobalSetTools::class, 'getGlobalSet'))->getParameters();

        expect($params)->toHaveCount(2);

        expect($params[0]->getName())->toBe('handle')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[0]->getType()?->getName())->toBe('string')
            ->and($params[1]->getName())->toBe('context')
            ->and($params[1]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(GlobalSetTools::class, 'getGlobalSet'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('GlobalSetTools update_global_set', function () {
    it('exposes update_global_set with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(GlobalSetTools::class, 'updateGlobalSet'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe('update_global_set');
    });

    it('is marked dangerous in the CONTENT category', function () {
        $meta = (new ReflectionMethod(GlobalSetTools::class, 'updateGlobalSet'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires handle and fields, with an optional context', function () {
        $params = (new ReflectionMethod(GlobalSetTools::class, 'updateGlobalSet'))->getParameters();

        expect($params[0]->getName())->toBe('handle')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[1]->getName())->toBe('fields')
            ->and($params[1]->isOptional())->toBeFalse()
            ->and($params[2]->getName())->toBe('context')
            ->and($params[2]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(GlobalSetTools::class, 'updateGlobalSet'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('GlobalSetTools class structure', function () {
    it('exposes exactly three tools', function () {
        $names = [];

        foreach ((new ReflectionClass(GlobalSetTools::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpTool::class);

            if ($attributes === []) {
                continue;
            }

            $names[] = $attributes[0]->newInstance()->name;
        }

        sort($names);

        expect($names)->toBe(['get_global_set', 'list_globals', 'update_global_set']);
    });
});
