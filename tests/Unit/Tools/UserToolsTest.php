<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\UserTools;

describe('UserTools get_user', function () {
    it('exposes get_user with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(UserTools::class, 'getUser'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe('get_user');
    });

    it('is not dangerous and sits in the CONTENT category', function () {
        $meta = (new ReflectionMethod(UserTools::class, 'getUser'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeFalse()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('takes optional id, email and username plus a context', function () {
        $params = (new ReflectionMethod(UserTools::class, 'getUser'))->getParameters();

        expect($params)->toHaveCount(4);

        expect($params[0]->getName())->toBe('id')
            ->and($params[0]->getType()?->getName())->toBe('int')
            ->and($params[0]->getType()?->allowsNull())->toBeTrue()
            ->and($params[0]->getDefaultValue())->toBeNull();

        expect($params[1]->getName())->toBe('email')
            ->and($params[1]->getType()?->getName())->toBe('string')
            ->and($params[1]->getType()?->allowsNull())->toBeTrue()
            ->and($params[1]->getDefaultValue())->toBeNull();

        expect($params[2]->getName())->toBe('username')
            ->and($params[2]->getType()?->getName())->toBe('string')
            ->and($params[2]->getType()?->allowsNull())->toBeTrue()
            ->and($params[2]->getDefaultValue())->toBeNull();

        expect($params[3]->getName())->toBe('context')
            ->and($params[3]->isOptional())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(UserTools::class, 'getUser'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('UserTools create_user', function () {
    it('exposes create_user with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(UserTools::class, 'createUser'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe('create_user');
    });

    it('is marked dangerous in the CONTENT category', function () {
        $meta = (new ReflectionMethod(UserTools::class, 'createUser'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires email, with optional username, fullName, groups, admin, activate and context', function () {
        $params = (new ReflectionMethod(UserTools::class, 'createUser'))->getParameters();

        expect($params)->toHaveCount(7);

        expect($params[0]->getName())->toBe('email')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[0]->getType()?->getName())->toBe('string')
            ->and($params[0]->getType()?->allowsNull())->toBeFalse();

        expect($params[1]->getName())->toBe('username')
            ->and($params[1]->getType()?->allowsNull())->toBeTrue()
            ->and($params[1]->getDefaultValue())->toBeNull();

        expect($params[2]->getName())->toBe('fullName')
            ->and($params[2]->getType()?->allowsNull())->toBeTrue()
            ->and($params[2]->getDefaultValue())->toBeNull();

        expect($params[3]->getName())->toBe('groups')
            ->and($params[3]->getType()?->getName())->toBe('string')
            ->and($params[3]->getType()?->allowsNull())->toBeTrue()
            ->and($params[3]->getDefaultValue())->toBeNull();

        expect($params[4]->getName())->toBe('admin')
            ->and($params[4]->getType()?->getName())->toBe('bool')
            ->and($params[4]->getType()?->allowsNull())->toBeFalse()
            ->and($params[4]->getDefaultValue())->toBeFalse();

        expect($params[5]->getName())->toBe('activate')
            ->and($params[5]->getType()?->getName())->toBe('bool')
            ->and($params[5]->getType()?->allowsNull())->toBeFalse()
            ->and($params[5]->getDefaultValue())->toBeFalse();

        expect($params[6]->getName())->toBe('context')
            ->and($params[6]->isOptional())->toBeTrue();
    });

    it('documents that groups is a JSON array of handles', function () {
        $description = (new ReflectionMethod(UserTools::class, 'createUser'))
            ->getAttributes(McpTool::class)[0]->newInstance()->description;

        expect($description)->toContain('groups')
            ->and($description)->toContain('JSON array');
    });

    it('returns array', function () {
        expect((new ReflectionMethod(UserTools::class, 'createUser'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('UserTools update_user', function () {
    it('exposes update_user with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(UserTools::class, 'updateUser'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe('update_user');
    });

    it('is marked dangerous in the CONTENT category', function () {
        $meta = (new ReflectionMethod(UserTools::class, 'updateUser'))
            ->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    });

    it('requires id, with optional email, username, fullName, groups, admin and context', function () {
        $params = (new ReflectionMethod(UserTools::class, 'updateUser'))->getParameters();

        expect($params)->toHaveCount(7);

        expect($params[0]->getName())->toBe('id')
            ->and($params[0]->isOptional())->toBeFalse()
            ->and($params[0]->getType()?->getName())->toBe('int')
            ->and($params[0]->getType()?->allowsNull())->toBeFalse();

        expect($params[1]->getName())->toBe('email')
            ->and($params[1]->getType()?->allowsNull())->toBeTrue()
            ->and($params[1]->getDefaultValue())->toBeNull();

        expect($params[2]->getName())->toBe('username')
            ->and($params[2]->getType()?->allowsNull())->toBeTrue()
            ->and($params[2]->getDefaultValue())->toBeNull();

        expect($params[3]->getName())->toBe('fullName')
            ->and($params[3]->getType()?->allowsNull())->toBeTrue()
            ->and($params[3]->getDefaultValue())->toBeNull();

        expect($params[4]->getName())->toBe('groups')
            ->and($params[4]->getType()?->getName())->toBe('string')
            ->and($params[4]->getType()?->allowsNull())->toBeTrue()
            ->and($params[4]->getDefaultValue())->toBeNull();

        expect($params[5]->getName())->toBe('admin')
            ->and($params[5]->getType()?->getName())->toBe('bool')
            ->and($params[5]->getType()?->allowsNull())->toBeTrue()
            ->and($params[5]->getDefaultValue())->toBeNull();

        expect($params[6]->getName())->toBe('context')
            ->and($params[6]->isOptional())->toBeTrue();
    });

    it('documents that omitted fields are untouched', function () {
        $description = (new ReflectionMethod(UserTools::class, 'updateUser'))
            ->getAttributes(McpTool::class)[0]->newInstance()->description;

        expect($description)->toContain('untouched');
    });

    it('returns array', function () {
        expect((new ReflectionMethod(UserTools::class, 'updateUser'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('UserTools class structure', function () {
    it('exposes exactly four tools', function () {
        $names = [];

        foreach ((new ReflectionClass(UserTools::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpTool::class);

            if ($attributes === []) {
                continue;
            }

            $names[] = $attributes[0]->newInstance()->name;
        }

        sort($names);

        expect($names)->toBe(['create_user', 'get_user', 'list_users', 'update_user']);
    });

    it('does not expose a delete_user tool', function () {
        expect(method_exists(UserTools::class, 'deleteUser'))->toBeFalse();
    });

    it('keeps group resolution and serialization private so tool discovery ignores them', function () {
        foreach (['resolveGroups', 'applyGroups', 'serializeUser'] as $name) {
            expect((new ReflectionMethod(UserTools::class, $name))->isPrivate())->toBeTrue();
        }
    });
});

describe('UserTools date serialization', function () {
    it('formats dates as ISO-8601 with a timezone offset', function () {
        $source = file_get_contents((new ReflectionClass(UserTools::class))->getFileName());

        expect($source)->toContain("lastLoginDate?->format('c')")
            ->and($source)->toContain("dateCreated?->format('c')")
            ->and($source)->not->toContain("format('Y-m-d H:i:s')");
    });
});
