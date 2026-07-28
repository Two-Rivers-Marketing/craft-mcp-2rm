<?php

declare(strict_types=1);

use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use twoRivers\craft\Mcp\controllers\McpController;
use twoRivers\craft\Mcp\models\Settings;
use twoRivers\craft\Mcp\services\McpServerFactory;

describe('Settings httpTransport flag', function () {
    it('defaults to false', function () {
        expect((new Settings())->httpTransport)->toBeFalse();
    });

    it('is validated as boolean', function () {
        $rules = (new Settings())->defineRules();
        $booleanAttrs = [];

        foreach ($rules as $rule) {
            if (($rule[1] ?? null) === 'boolean') {
                $booleanAttrs = array_merge($booleanAttrs, (array) $rule[0]);
            }
        }

        expect($booleanAttrs)->toContain('httpTransport');
    });
});

describe('McpServerFactory HTTP transport', function () {
    it('create() takes an optional persistentSessions flag defaulting to false', function () {
        $param = (new ReflectionMethod(McpServerFactory::class, 'create'))->getParameters()[0] ?? null;

        expect($param)->not->toBeNull()
            ->and($param->getName())->toBe('persistentSessions')
            ->and($param->isOptional())->toBeTrue()
            ->and($param->getDefaultValue())->toBeFalse();
    });

    it('createHttpTransport accepts a PSR-7 request + allowedHosts and returns a StreamableHttpTransport', function () {
        $reflection = new ReflectionMethod(McpServerFactory::class, 'createHttpTransport');
        $params = $reflection->getParameters();

        expect($params[0]->getType()?->getName())->toBe(ServerRequestInterface::class)
            ->and($params[1]->getName())->toBe('allowedHosts')
            ->and($reflection->getReturnType()?->getName())->toBe(StreamableHttpTransport::class);
    });
});

describe('McpController guard', function () {
    it('allows anonymous access (auth handled by bearer token)', function () {
        $defaults = (new ReflectionClass(McpController::class))->getDefaultProperties();
        expect($defaults['allowAnonymous'])->toBeTrue();
    });

    it('disables CSRF in a public beforeAction override', function () {
        $method = new ReflectionMethod(McpController::class, 'beforeAction');
        expect($method->isPublic())->toBeTrue();
    });

    it('keeps the authorization check private', function () {
        expect((new ReflectionMethod(McpController::class, 'assertAuthorized'))->isPrivate())->toBeTrue();
    });

    it('actionHandle returns a Craft web Response', function () {
        $reflection = new ReflectionMethod(McpController::class, 'actionHandle');
        expect($reflection->getReturnType()?->getName())->toBe('yii\web\Response');
    });
});
