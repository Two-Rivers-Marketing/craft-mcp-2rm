<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\GlobalSetTools;

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
