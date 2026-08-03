<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\TemplateTools;

describe('TemplateTools render_template', function () {
    it('exposes render_template with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(TemplateTools::class, 'renderTemplate'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('render_template')
            ->and($instance->description)->toContain('site template mode')
            ->and($instance->description)->toContain('variables')
            ->and($instance->description)->toContain('truncated')
            ->and($instance->description)->toContain('root key');
    });

    it('is marked dangerous in the content category', function () {
        $attributes = (new ReflectionMethod(TemplateTools::class, 'renderTemplate'))->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });

    it('requires template, with optional variables, maxLength and context', function () {
        $parameters = (new ReflectionMethod(TemplateTools::class, 'renderTemplate'))->getParameters();

        expect($parameters)->toHaveCount(4);

        expect($parameters[0]->getName())->toBe('template')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('string');

        expect($parameters[1]->getName())->toBe('variables')
            ->and($parameters[1]->isOptional())->toBeTrue()
            ->and($parameters[1]->getType()?->getName())->toBe('string')
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue()
            ->and($parameters[1]->getDefaultValue())->toBeNull();

        expect($parameters[2]->getName())->toBe('maxLength')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->getName())->toBe('int')
            ->and($parameters[2]->getDefaultValue())->toBe(32768);

        expect($parameters[3]->getName())->toBe('context')
            ->and($parameters[3]->isOptional())->toBeTrue()
            ->and($parameters[3]->getType()?->allowsNull())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(TemplateTools::class, 'renderTemplate'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('TemplateTools tool count', function () {
    it('has exactly 1 public method with the McpTool attribute', function () {
        $methods = (new ReflectionClass(TemplateTools::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter(
            $methods,
            static fn (ReflectionMethod $method): bool => $method->getAttributes(McpTool::class) !== [],
        );

        expect($toolMethods)->toHaveCount(1);
    });

    it('keeps its render and decode helpers private so discovery ignores them', function () {
        $reflection = new ReflectionClass(TemplateTools::class);

        expect($reflection->getMethod('render')->isPrivate())->toBeTrue()
            ->and($reflection->getMethod('decodeVariables')->isPrivate())->toBeTrue();
    });
});
