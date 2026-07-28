<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\NavigationTools;

describe('NavigationTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(NavigationTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method returning bool', function () {
        $reflection = new ReflectionMethod(NavigationTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('reports unavailable when navigation plugin is absent', function () {
        expect(NavigationTools::isAvailable())->toBeFalse();
    });
});

describe('NavigationTools tools', function () {
    $expected = [
        'listNavs' => 'list_navs',
        'getNav' => 'get_nav',
        'createNav' => 'create_nav',
        'createNode' => 'create_node',
        'updateNode' => 'update_node',
        'deleteNode' => 'delete_node',
    ];

    it('exposes each tool with the expected McpTool name', function () use ($expected) {
        foreach ($expected as $method => $toolName) {
            $attributes = (new ReflectionMethod(NavigationTools::class, $method))->getAttributes(McpTool::class);
            expect($attributes)->toHaveCount(1)
                ->and($attributes[0]->newInstance()->name)->toBe($toolName);
        }
    });

    it('marks the three write tools dangerous and the two reads not', function () {
        $dangerous = fn (string $m): bool => (new ReflectionMethod(NavigationTools::class, $m))
            ->getAttributes(McpToolMeta::class)[0]->newInstance()->dangerous;

        expect($dangerous('createNav'))->toBeTrue()
            ->and($dangerous('createNode'))->toBeTrue()
            ->and($dangerous('updateNode'))->toBeTrue()
            ->and($dangerous('deleteNode'))->toBeTrue()
            ->and($dangerous('listNavs'))->toBeFalse()
            ->and($dangerous('getNav'))->toBeFalse();
    });

    it('categorizes every tool as CONTENT', function () use ($expected) {
        foreach (array_keys($expected) as $method) {
            $meta = (new ReflectionMethod(NavigationTools::class, $method))
                ->getAttributes(McpToolMeta::class)[0]->newInstance();
            expect($meta->category)->toBe(ToolCategory::CONTENT);
        }
    });

    it('all tool methods return array', function () use ($expected) {
        foreach (array_keys($expected) as $method) {
            expect((new ReflectionMethod(NavigationTools::class, $method))->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('NavigationTools tool count', function () {
    it('has exactly 6 public methods with McpTool attribute', function () {
        $methods = (new ReflectionClass(NavigationTools::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            if ($method->getName() === 'isAvailable') {
                return false;
            }

            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(6);
    });
});
