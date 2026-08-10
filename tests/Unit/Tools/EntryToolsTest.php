<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\EntryTools;

describe('EntryTools create_draft', function () {
    it('exposes create_draft with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(EntryTools::class, 'createDraft'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('create_draft')
            ->and($instance->description)->toContain('EXISTING')
            ->and($instance->description)->toContain('create_entry')
            ->and($instance->description)->toContain('draftId');
    });

    it('is marked dangerous in the CONTENT category', function () {
        $meta = (new ReflectionMethod(EntryTools::class, 'createDraft'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires entryId, with optional name, notes, title, fields and context', function () {
        $params = (new ReflectionMethod(EntryTools::class, 'createDraft'))->getParameters();

        expect($params)->toHaveCount(6);

        expect($params[0]->getName())->toBe('entryId')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[0]->getType()?->getName())->toBe('int');

        expect($params[1]->getName())->toBe('name')
            ->and($params[1]->isOptional())->toBeTrue()
            ->and($params[1]->getType()?->allowsNull())->toBeTrue();

        expect($params[2]->getName())->toBe('notes')
            ->and($params[2]->isOptional())->toBeTrue()
            ->and($params[2]->getType()?->allowsNull())->toBeTrue();

        expect($params[3]->getName())->toBe('title')
            ->and($params[3]->isOptional())->toBeTrue()
            ->and($params[3]->getType()?->allowsNull())->toBeTrue();

        expect($params[4]->getName())->toBe('fields')
            ->and($params[4]->isOptional())->toBeTrue()
            ->and($params[4]->getType()?->allowsNull())->toBeTrue();

        expect($params[5]->getName())->toBe('context')
            ->and($params[5]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(EntryTools::class, 'createDraft'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('EntryTools publish_draft', function () {
    it('exposes publish_draft with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(EntryTools::class, 'publishDraft'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('publish_draft')
            ->and($instance->description)->toContain('dryRun')
            ->and($instance->description)->toContain('NOT the entry id');
    });

    it('is marked dangerous in the CONTENT category', function () {
        $meta = (new ReflectionMethod(EntryTools::class, 'publishDraft'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires draftId, with dryRun defaulting to false and an optional context', function () {
        $params = (new ReflectionMethod(EntryTools::class, 'publishDraft'))->getParameters();

        expect($params)->toHaveCount(3);

        expect($params[0]->getName())->toBe('draftId')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[0]->getType()?->getName())->toBe('int');

        expect($params[1]->getName())->toBe('dryRun')
            ->and($params[1]->isOptional())->toBeTrue()
            ->and($params[1]->getType()?->getName())->toBe('bool')
            ->and($params[1]->getDefaultValue())->toBeFalse();

        expect($params[2]->getName())->toBe('context')
            ->and($params[2]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(EntryTools::class, 'publishDraft'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('EntryTools list_drafts', function () {
    it('exposes list_drafts with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(EntryTools::class, 'listDrafts'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_drafts')
            ->and($instance->description)->toContain('entryId')
            ->and($instance->description)->toContain('draftId');
    });

    it('is a read tool in the CONTENT category', function () {
        $meta = (new ReflectionMethod(EntryTools::class, 'listDrafts'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeFalse()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('takes an optional entryId, a limit defaulting to 20, includeFields and an optional context', function () {
        $params = (new ReflectionMethod(EntryTools::class, 'listDrafts'))->getParameters();

        expect($params)->toHaveCount(4);

        expect($params[0]->getName())->toBe('entryId')
            ->and($params[0]->isOptional())->toBeTrue()
            ->and($params[0]->getType()?->allowsNull())->toBeTrue();

        expect($params[1]->getName())->toBe('limit')
            ->and($params[1]->isOptional())->toBeTrue()
            ->and($params[1]->getType()?->getName())->toBe('int')
            ->and($params[1]->getDefaultValue())->toBe(20);

        expect($params[2]->getName())->toBe('includeFields')
            ->and($params[2]->isOptional())->toBeTrue()
            ->and($params[2]->getType()?->getName())->toBe('bool')
            ->and($params[2]->getDefaultValue())->toBeFalse();

        expect($params[3]->getName())->toBe('context')
            ->and($params[3]->isOptional())->toBeTrue();
    });

    // Live measurement on KCMA: one draft serialized to 6.6KB, 5.4KB of which
    // was a single SEOmatic MetaBundle field. Field values must stay opt-in so
    // a 20-draft listing does not cost ~130KB of context.
    it('omits custom field values unless includeFields is opted into', function () {
        $params = (new ReflectionMethod(EntryTools::class, 'listDrafts'))->getParameters();

        $named = [];
        foreach ($params as $param) {
            $named[$param->getName()] = $param;
        }

        expect($named)->toHaveKey('includeFields');
        expect($named['includeFields']->getType()?->getName())->toBe('bool')
            ->and($named['includeFields']->getDefaultValue())->toBeFalse();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(EntryTools::class, 'listDrafts'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('EntryTools tool count', function () {
    it('has exactly 8 public methods with the McpTool attribute', function () {
        $methods = (new ReflectionClass(EntryTools::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, fn ($method) => !empty($method->getAttributes(McpTool::class)));

        expect($toolMethods)->toHaveCount(8);
    });

    it('exposes the expected tool names', function () {
        $methods = (new ReflectionClass(EntryTools::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $names = [];
        foreach ($methods as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ($attributes === []) {
                continue;
            }
            $names[] = $attributes[0]->newInstance()->name;
        }

        sort($names);

        expect($names)->toBe([
            'create_draft',
            'create_entry',
            'delete_entry',
            'get_entry',
            'list_drafts',
            'list_entries',
            'publish_draft',
            'update_entry',
        ]);
    });
});
