<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\Mcp;

describe('Plugin handle', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

    it('matches the handle declared in composer.json', function () use ($composer) {
        expect($composer['extra']['handle'])->toBe(Mcp::HANDLE);
    });

    it('does not use the `mcp` handle Craft 5.12 claimed for its native MCP server', function () {
        expect(Mcp::HANDLE)->not->toBe('mcp')
            ->and(Mcp::LEGACY_HANDLE)->toBe('mcp');
    });

    it('allows an mcp/sdk version Craft 5.12.0.1 and 5.12.1 can install', function () use ($composer) {
        expect($composer['require']['mcp/sdk'])->toContain('0.8.1')
            ->and($composer['require']['mcp/sdk'])->toContain('0.9.0');
    });
});
