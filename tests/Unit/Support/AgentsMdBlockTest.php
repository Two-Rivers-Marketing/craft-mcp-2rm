<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\AgentsMdBlock;

describe('AgentsMdBlock::render', function () {
    it('wraps 3-6 lines of content in the start and end markers', function () {
        $lines = explode("\n", AgentsMdBlock::render());

        expect($lines[0])->toBe(AgentsMdBlock::START)
            ->and(end($lines))->toBe(AgentsMdBlock::END)
            ->and(count($lines) - 2)->toBeBetween(3, 6);
    });

    it('points agents at the conventions resources and the standards page', function () {
        $block = AgentsMdBlock::render();

        expect($block)->toContain('craft://conventions/*')
            ->and($block)->toContain('content-builder-standards')
            ->and($block)->toContain('vendor/2rm/craft-mcp/docs/conventions/')
            ->and($block)->toContain('/code-review');
    });
});

describe('AgentsMdBlock::apply', function () {
    it('inserts the block into an empty file', function () {
        expect(AgentsMdBlock::apply(''))->toBe(AgentsMdBlock::render() . "\n");
    });

    it('treats a whitespace-only file as empty', function () {
        expect(AgentsMdBlock::apply("\n\n"))->toBe(AgentsMdBlock::render() . "\n");
    });

    it('appends to existing content after a blank line', function () {
        $result = AgentsMdBlock::apply("# Project\n\nSome notes.\n");

        expect($result)->toStartWith("# Project\n\nSome notes.\n\n" . AgentsMdBlock::START)
            ->and($result)->toEndWith(AgentsMdBlock::END . "\n");
    });

    it('replaces an existing block in place', function () {
        $stale = AgentsMdBlock::START . "\nold text\n" . AgentsMdBlock::END;
        $result = AgentsMdBlock::apply("# Top\n\n{$stale}\n\n## After\n");

        expect($result)->toBe("# Top\n\n" . AgentsMdBlock::render() . "\n\n## After\n")
            ->and($result)->not->toContain('old text');
    });

    it('is idempotent', function () {
        $once = AgentsMdBlock::apply("# Project\n");
        $twice = AgentsMdBlock::apply($once);

        expect($twice)->toBe($once)
            ->and(substr_count($twice, AgentsMdBlock::START))->toBe(1);
    });

    it('is idempotent on an empty file', function () {
        $once = AgentsMdBlock::apply('');

        expect(AgentsMdBlock::apply($once))->toBe($once);
    });

    it('rejects a start marker with no end marker', function () {
        AgentsMdBlock::apply("# Top\n" . AgentsMdBlock::START . "\nhalf a block\n");
    })->throws(InvalidArgumentException::class);
});
