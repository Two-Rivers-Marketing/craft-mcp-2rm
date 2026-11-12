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

describe('AgentsMdBlock vendor path', function () {
    $defaultBlock = implode("\n", [
        '<!-- craft-mcp:conventions:start -->',
        '## Craft MCP conventions',
        '',
        'Content-builder work (Neo `contentBuilder`, block types, block templates, Section Options, content writes) follows the craft-mcp conventions.',
        'Read them through the MCP resources `craft://conventions/*` (list `content-builder-standards` first) or from `vendor/2rm/craft-mcp/docs/conventions/`.',
        '`/code-review` Standards passes check content-builder changes against `content-builder-standards`.',
        '<!-- craft-mcp:conventions:end -->',
    ]);

    it('keeps the default block byte-for-byte unchanged', function () use ($defaultBlock) {
        expect(AgentsMdBlock::render())->toBe($defaultBlock)
            ->and(AgentsMdBlock::render('vendor'))->toBe($defaultBlock);
    });

    it('changes only the path line for a custom vendor path', function () use ($defaultBlock) {
        $default = explode("\n", $defaultBlock);
        $custom = explode("\n", AgentsMdBlock::render('app/vendor'));
        $changed = array_keys(array_diff_assoc($custom, $default));

        expect($custom)->toHaveCount(count($default))
            ->and($changed)->toBe([4])
            ->and($custom[4])->toContain('`app/vendor/2rm/craft-mcp/docs/conventions/`')
            ->and($custom[4])->not->toContain('`vendor/2rm');
    });

    it('trims a trailing slash', function () {
        expect(AgentsMdBlock::render('app/vendor/'))->toBe(AgentsMdBlock::render('app/vendor'))
            ->and(AgentsMdBlock::render('vendor/'))->toBe(AgentsMdBlock::render());
    });

    it('rejects an empty vendor path', function (string $path) {
        AgentsMdBlock::render($path);
    })->with(['', '/', '  '])->throws(InvalidArgumentException::class, 'must not be empty');

    it('inserts with a custom path, then replaces in place when the path changes', function () {
        $inserted = AgentsMdBlock::apply("# Top\n\n## After\n", 'app/vendor');

        expect($inserted)->toBe("# Top\n\n## After\n\n" . AgentsMdBlock::render('app/vendor') . "\n");

        $replaced = AgentsMdBlock::apply($inserted, 'site/vendor');

        expect($replaced)->toBe("# Top\n\n## After\n\n" . AgentsMdBlock::render('site/vendor') . "\n")
            ->and($replaced)->not->toContain('app/vendor')
            ->and(substr_count($replaced, AgentsMdBlock::START))->toBe(1);
    });

    it('is idempotent with a custom path', function () {
        $once = AgentsMdBlock::apply("# Project\n", 'app/vendor');

        expect(AgentsMdBlock::apply($once, 'app/vendor'))->toBe($once)
            ->and(AgentsMdBlock::apply($once, 'app/vendor/'))->toBe($once);
    });
});

describe('AgentsMdBlock::printable', function () {
    it('is the block followed by exactly one newline', function () {
        expect(AgentsMdBlock::printable())->toBe(AgentsMdBlock::render() . "\n")
            ->and(AgentsMdBlock::printable('app/vendor'))->toBe(AgentsMdBlock::render('app/vendor') . "\n")
            ->and(AgentsMdBlock::printable())->not->toEndWith("\n\n");
    });
});
