<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use InvalidArgumentException;

/**
 * Builds and applies the marked craft-mcp conventions block in an AGENTS.md file.
 *
 * Pure string logic: the console command reads and writes the file, this class
 * only decides what the new content is. Applying the block twice yields the
 * same text as applying it once.
 *
 * @author 2RM
 */
final class AgentsMdBlock {
    public const string START = '<!-- craft-mcp:conventions:start -->';

    public const string END = '<!-- craft-mcp:conventions:end -->';

    /**
     * The block, markers included, without a trailing newline.
     */
    public static function render(): string {
        return implode("\n", [
            self::START,
            '## Craft MCP conventions',
            '',
            'Content-builder work (Neo `contentBuilder`, block types, block templates, Section Options, content writes) follows the craft-mcp conventions.',
            'Read them through the MCP resources `craft://conventions/*` (list `content-builder-standards` first) or from `vendor/2rm/craft-mcp/docs/conventions/`.',
            '`/code-review` Standards passes check content-builder changes against `content-builder-standards`.',
            self::END,
        ]);
    }

    /**
     * Insert the block into the content, or replace an existing block in place.
     *
     * @throws InvalidArgumentException when a start marker has no end marker after it
     */
    public static function apply(string $content): string {
        $block = self::render();
        $start = strpos($content, self::START);

        if ($start === false) {
            return self::append($content, $block);
        }

        $end = strpos($content, self::END, $start);

        if ($end === false) {
            throw new InvalidArgumentException('Found "' . self::START . '" without a matching "' . self::END . '". Fix or remove the marker, then re-run.');
        }

        return substr($content, 0, $start) . $block . substr($content, $end + strlen(self::END));
    }

    private static function append(string $content, string $block): string {
        if (trim($content) === '') {
            return $block . "\n";
        }

        return rtrim($content) . "\n\n" . $block . "\n";
    }
}
