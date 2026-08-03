<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\SeomaticTools;

describe('SeomaticTools class structure', function () {
    it('implements ConditionalToolProvider', function () {
        expect(is_subclass_of(SeomaticTools::class, ConditionalToolProvider::class))->toBeTrue();
    });

    it('has a static isAvailable returning bool', function () {
        expect(method_exists(SeomaticTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(SeomaticTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('exposes exactly the get_seo and update_seo tools', function () {
        $reflection = new ReflectionClass(SeomaticTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $names = [];
        foreach ($methods as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ($attributes === []) {
                continue;
            }
            $names[] = $attributes[0]->newInstance()->name;
        }

        sort($names);

        expect($names)->toBe(['get_seo', 'update_seo']);
    });

    it('keeps every SEOmatic-touching helper private so tool discovery ignores them', function () {
        $reflection = new ReflectionClass(SeomaticTools::class);

        foreach (['findEntry', 'seoFieldHandle', 'metaBundles', 'prop', 'str', 'applyValues', 'resolveBundle', 'classifyOrigin'] as $helper) {
            expect($reflection->getMethod($helper)->isPrivate())
                ->toBeTrue("{$helper}() must be private");
        }
    });
});

describe('SeomaticTools lazy-reference discipline', function () {
    // The load-bearing guarantee: this class is registered conditionally, but it
    // is always autoloaded so ToolRegistry can ask isAvailable(). If any SEOmatic
    // class were named in resolvable position, that would fatal in an install
    // without the plugin — which is every environment this suite runs in.
    it('isAvailable() returns false without fataling when SEOmatic is absent', function () {
        expect(class_exists('nystudio107\seomatic\Seomatic'))->toBeFalse();
        expect(SeomaticTools::isAvailable())->toBeFalse();
    });

    it('names SEOmatic classes only as string constants, never as imports', function () {
        $source = file_get_contents((new ReflectionClass(SeomaticTools::class))->getFileName());

        expect($source)->not->toContain('use nystudio107\\');
    });
});

describe('SeomaticTools get_seo', function () {
    it('is a non-dangerous CONTENT tool', function () {
        $reflection = new ReflectionMethod(SeomaticTools::class, 'getSeo');

        $meta = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();
        expect($meta->category)->toBe(ToolCategory::CONTENT)
            ->and($meta->dangerous)->toBeFalse();
    });

    it('promises a compact shape and documents the inheritance reporting', function () {
        $reflection = new ReflectionMethod(SeomaticTools::class, 'getSeo');
        $tool = $reflection->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->name)->toBe('get_seo')
            ->and($tool->description)->toContain('flat')
            ->and($tool->description)->toContain('sources')
            ->and($tool->description)->toContain('MetaBundle');
    });

    it('takes entryId plus the optional context', function () {
        $parameters = (new ReflectionMethod(SeomaticTools::class, 'getSeo'))->getParameters();

        expect($parameters)->toHaveCount(2);

        expect($parameters[0]->getName())->toBe('entryId')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('int');

        expect($parameters[1]->getName())->toBe('context')
            ->and($parameters[1]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(SeomaticTools::class, 'getSeo'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('SeomaticTools update_seo', function () {
    it('is flagged dangerous', function () {
        $reflection = new ReflectionMethod(SeomaticTools::class, 'updateSeo');

        $meta = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();
        expect($meta->category)->toBe(ToolCategory::CONTENT)
            ->and($meta->dangerous)->toBeTrue();
    });

    it('documents that only non-null params change and that sources switch to literal mode', function () {
        $reflection = new ReflectionMethod(SeomaticTools::class, 'updateSeo');
        $tool = $reflection->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->name)->toBe('update_seo')
            ->and($tool->description)->toContain('non-null')
            ->and($tool->description)->toContain('fromCustom');
    });

    it('requires entryId and makes every SEO field optional and nullable', function () {
        $parameters = (new ReflectionMethod(SeomaticTools::class, 'updateSeo'))->getParameters();

        expect($parameters[0]->getName())->toBe('entryId')
            ->and($parameters[0]->isOptional())->toBeFalse()
            ->and($parameters[0]->getType()?->getName())->toBe('int');

        $seoFields = [
            'title', 'description', 'keywords', 'canonicalUrl', 'robots',
            'ogTitle', 'ogDescription', 'ogImage',
            'twitterTitle', 'twitterDescription', 'twitterImage',
        ];

        $named = [];
        foreach ($parameters as $parameter) {
            $named[$parameter->getName()] = $parameter;
        }

        foreach ($seoFields as $field) {
            expect($named)->toHaveKey($field);
            expect($named[$field]->isOptional())->toBeTrue("{$field} must be optional");
            expect($named[$field]->getType()?->allowsNull())->toBeTrue("{$field} must be nullable");
            expect($named[$field]->getDefaultValue())->toBeNull("{$field} must default to null");
        }

        // entryId + 11 SEO fields + context
        expect($parameters)->toHaveCount(13);
    });

    it('accepts the same field set that get_seo reports', function () {
        // Drift guard: the write surface and the read projection must stay in
        // step, or a caller can read a field it cannot write back.
        $reflection = new ReflectionClass(SeomaticTools::class);
        $seoFields = $reflection->getConstant('SEO_FIELDS');

        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(SeomaticTools::class, 'updateSeo'))->getParameters(),
        );

        foreach (array_keys($seoFields) as $key) {
            expect($params)->toContain($key);
        }
    });

    it('returns array', function () {
        expect((new ReflectionMethod(SeomaticTools::class, 'updateSeo'))->getReturnType()?->getName())->toBe('array');
    });
});
