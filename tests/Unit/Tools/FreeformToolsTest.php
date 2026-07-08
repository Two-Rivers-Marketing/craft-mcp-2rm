<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\FreeformTools;

describe('FreeformTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(FreeformTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has isAvailable static method', function () {
        expect(method_exists(FreeformTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(FreeformTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has list_forms tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listForms');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_forms')
            ->and($instance->description)->toContain('forms');
    });

    it('has get_form tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getForm');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_form')
            ->and($instance->description)->toContain('field layout')
            ->and($instance->description)->toContain('notification')
            ->and($instance->description)->toContain('element connections');
    });

    it('has list_submissions tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listSubmissions');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_submissions')
            ->and($instance->description)->toContain('submissions');
    });

    it('has get_submission tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getSubmission');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_submission')
            ->and($instance->description)->toContain('field value');
    });

    it('has delete_submission tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'deleteSubmission');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('delete_submission')
            ->and($instance->description)->toContain('dryRun');
    });

    it('has export_submissions tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'exportSubmissions');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('export_submissions')
            ->and($instance->description)->toContain('CSV');
    });
});

describe('FreeformTools dangerous flags and categories', function () {
    it('marks list_forms safe in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listForms');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeFalse();
    });

    it('marks get_form safe in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getForm');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeFalse();
    });

    it('marks list_submissions safe in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listSubmissions');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeFalse();
    });

    it('marks get_submission safe in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getSubmission');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeFalse();
    });

    it('marks delete_submission dangerous in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'deleteSubmission');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });

    it('marks export_submissions dangerous in the content category', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'exportSubmissions');
        $instance = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($instance->category)->toBe(ToolCategory::CONTENT)
            ->and($instance->dangerous)->toBeTrue();
    });
});

describe('FreeformTools method signatures', function () {
    it('listForms accepts only an optional context parameter', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listForms');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getName())->toBe('context')
            ->and($parameters[0]->isOptional())->toBeTrue();
    });

    it('getForm accepts nullable handle, nullable id, and context', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getForm');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(3);

        expect($parameters[0]->getName())->toBe('handle')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[1]->getName())->toBe('id')
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue();
    });

    it('listSubmissions accepts formHandle, limit, offset, status, and context', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'listSubmissions');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(5);

        expect($parameters[0]->getName())->toBe('formHandle')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[1]->getName())->toBe('limit')
            ->and($parameters[1]->getType()?->getName())->toBe('int')
            ->and($parameters[1]->getDefaultValue())->toBe(20);

        expect($parameters[2]->getName())->toBe('offset')
            ->and($parameters[2]->getDefaultValue())->toBe(0);

        expect($parameters[3]->getName())->toBe('status')
            ->and($parameters[3]->getType()?->allowsNull())->toBeTrue();
    });

    it('getSubmission requires an int id', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'getSubmission');
        $parameters = $reflection->getParameters();

        expect($parameters[0]->getName())->toBe('id')
            ->and($parameters[0]->getType()?->getName())->toBe('int')
            ->and($parameters[0]->isOptional())->toBeFalse();
    });

    it('deleteSubmission requires id with optional dryRun defaulting false', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'deleteSubmission');
        $parameters = $reflection->getParameters();

        expect($parameters[0]->getName())->toBe('id')
            ->and($parameters[0]->isOptional())->toBeFalse();

        expect($parameters[1]->getName())->toBe('dryRun')
            ->and($parameters[1]->getType()?->getName())->toBe('bool')
            ->and($parameters[1]->getDefaultValue())->toBeFalse();
    });

    it('exportSubmissions requires formHandle with optional format/limit/since', function () {
        $reflection = new ReflectionMethod(FreeformTools::class, 'exportSubmissions');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(5);

        expect($parameters[0]->getName())->toBe('formHandle')
            ->and($parameters[0]->isOptional())->toBeFalse();

        expect($parameters[1]->getName())->toBe('format')
            ->and($parameters[1]->getDefaultValue())->toBe('csv');

        expect($parameters[2]->getName())->toBe('limit')
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[3]->getName())->toBe('since')
            ->and($parameters[3]->getType()?->allowsNull())->toBeTrue();
    });

    it('all tool methods return array', function () {
        $methods = [
            'listForms', 'getForm', 'listSubmissions',
            'getSubmission', 'deleteSubmission', 'exportSubmissions',
        ];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(FreeformTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('FreeformTools tool count', function () {
    it('has exactly 6 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(FreeformTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            if ($method->getName() === 'isAvailable') {
                return false;
            }

            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(6);
    });
});

describe('FreeformTools availability without Freeform', function () {
    it('references Freeform plugin classes that are absent in this test environment', function () {
        // Freeform (solspace/craft-freeform) is intentionally not a dependency of this plugin.
        expect(class_exists('Solspace\Freeform\Freeform'))->toBeFalse();
    });

    it('reports unavailable without touching Craft when Freeform classes are absent', function () {
        // class_exists guard short-circuits before Craft::$app is accessed,
        // so this must not fatal even though Craft is not booted in tests.
        expect(FreeformTools::isAvailable())->toBeFalse();
    });
});
