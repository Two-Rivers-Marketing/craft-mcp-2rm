<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\AssetTools;

describe('AssetTools upload_asset tool structure', function () {
    it('has upload_asset tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(AssetTools::class, 'uploadAsset');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('upload_asset')
            ->and($instance->description)->toContain('volume')
            ->and($instance->description)->toContain('server');
    });

    it('marks upload_asset dangerous in the content category', function () {
        $reflection = new ReflectionMethod(AssetTools::class, 'uploadAsset');
        $attributes = $reflection->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });

    it('uploadAsset returns array', function () {
        $reflection = new ReflectionMethod(AssetTools::class, 'uploadAsset');
        expect($reflection->getReturnType()?->getName())->toBe('array');
    });
});

describe('AssetTools upload_asset method signature', function () {
    it('requires path and volume, with optional folder and context', function () {
        $reflection = new ReflectionMethod(AssetTools::class, 'uploadAsset');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(4);

        expect($parameters[0]->getName())->toBe('path')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('volume')
            ->and($parameters[1]->isOptional())->toBeFalse()
            ->and($parameters[1]->getType()?->getName())->toBe('string');

        expect($parameters[2]->getName())->toBe('folder')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('context')
            ->and($parameters[3]->isOptional())->toBeTrue();
    });
});

describe('AssetTools tool count', function () {
    it('has exactly 5 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(AssetTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter(
            $methods,
            static fn ($method) => !empty($method->getAttributes(McpTool::class)),
        );

        expect($toolMethods)->toHaveCount(5);
    });
});

describe('AssetTools::assertReadableFile (pure logic, no Craft boot)', function () {
    it('throws when the path does not exist', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'assertReadableFile');
        expect(fn () => $reflection->invoke($tools, '/definitely/not/a/real/path-' . uniqid()))
            ->toThrow(ToolCallException::class, 'not readable');
    });

    it('throws when the path is a directory, not a file', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'assertReadableFile');
        expect(fn () => $reflection->invoke($tools, sys_get_temp_dir()))
            ->toThrow(ToolCallException::class);
    });

    it('passes silently for a real, readable file', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'assertReadableFile');
        $path = tempnam(sys_get_temp_dir(), 'mcp-asset-test-');
        file_put_contents($path, 'content');

        try {
            expect($reflection->invoke($tools, $path))->toBeNull();
        } finally {
            unlink($path);
        }
    });
});

describe('AssetTools::sanitizeFilename (pure logic, no Craft boot)', function () {
    it('strips directory components', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'sanitizeFilename');
        expect($reflection->invoke($tools, '/etc/passwd'))->toBe('passwd')
            ->and($reflection->invoke($tools, '../../secret.txt'))->toBe('secret.txt');
    });

    it('replaces disallowed characters with underscores', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'sanitizeFilename');
        expect($reflection->invoke($tools, 'my photo #1 (final)!.png'))
            ->toBe('my_photo__1__final__.png');
    });

    it('preserves already-safe filenames', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'sanitizeFilename');
        expect($reflection->invoke($tools, 'rug-swatch_v2.jpg'))->toBe('rug-swatch_v2.jpg');
    });

    it('falls back to a default name when nothing survives sanitization', function () {
        $tools = new AssetTools();
        $reflection = new ReflectionMethod(AssetTools::class, 'sanitizeFilename');
        expect($reflection->invoke($tools, ''))->toBe('upload');
    });
});
