<?php

declare(strict_types=1);

use Mcp\Capability\Registry\Loader\LoaderInterface;
use twoRivers\craft\Mcp\services\McpServerFactory;
use twoRivers\craft\Mcp\support\GatedDiscoveryLoader;

describe('GatedDiscoveryLoader', function () {
    it('is an SDK registry loader', function () {
        expect(new ReflectionClass(GatedDiscoveryLoader::class)->implementsInterface(LoaderInterface::class))->toBeTrue();
    });

    it('exposes one admission check per element kind', function (string $method) {
        $reflection = new ReflectionMethod(GatedDiscoveryLoader::class, $method);

        expect($reflection->isPublic())->toBeTrue()
            ->and($reflection->isStatic())->toBeTrue()
            ->and((string) $reflection->getReturnType())->toBe('bool');
    })->with(['admitsTool', 'admitsPrompt', 'admitsResource', 'admitsResourceTemplate']);
});

describe('McpServerFactory registration', function () {
    $source = (string) file_get_contents((string) new ReflectionClass(McpServerFactory::class)->getFileName());

    it('registers discovered elements through the gated loader, not the SDK discovery', function () use ($source) {
        expect($source)->toContain('->addLoader(new GatedDiscoveryLoader(')
            ->and($source)->not->toContain('->setDiscovery(');
    });

    it('gates externally registered tools, prompts and resources', function () use ($source) {
        expect($source)->toContain('GatedDiscoveryLoader::admitsTool(')
            ->and($source)->toContain('GatedDiscoveryLoader::admitsPrompt(')
            ->and($source)->toContain('GatedDiscoveryLoader::admitsResource(');
    });
});
