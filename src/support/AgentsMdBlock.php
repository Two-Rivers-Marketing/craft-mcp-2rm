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

    public const string DEFAULT_VENDOR_PATH = 'vendor';

    /**
     * The block, markers included, without a trailing newline.
     *
     * @param string $vendorPath The vendor directory as seen from the AGENTS.md file's directory
     *
     * @throws InvalidArgumentException when the vendor path is empty
     */
    public static function render(string $vendorPath = self::DEFAULT_VENDOR_PATH): string {
        $conventionsPath = self::normalizeVendorPath($vendorPath) . '/2rm/craft-mcp/docs/conventions/';

        return implode("\n", [
            self::START,
            '## Craft MCP conventions',
            '',
            'Content-builder work (Neo `contentBuilder`, block types, block templates, Section Options, content writes) follows the craft-mcp conventions.',
            "Read them through the MCP resources `craft://conventions/*` (list `content-builder-standards` first) or from `{$conventionsPath}`.",
            '`/code-review` Standards passes check content-builder changes against `content-builder-standards`.',
            self::END,
        ]);
    }

    /**
     * What `--print` writes to stdout: the block followed by a single newline.
     *
     * @throws InvalidArgumentException when the vendor path is empty
     */
    public static function printable(string $vendorPath = self::DEFAULT_VENDOR_PATH): string {
        return self::render($vendorPath) . "\n";
    }

    /**
     * Insert the block into the content, or replace an existing block in place.
     *
     * @throws InvalidArgumentException when a start marker has no end marker after it, or the vendor path is empty
     */
    public static function apply(string $content, string $vendorPath = self::DEFAULT_VENDOR_PATH): string {
        $block = self::render($vendorPath);
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

    /**
     * Trim trailing slashes and reject an empty path.
     *
     * @throws InvalidArgumentException when nothing is left after trimming
     */
    public static function normalizeVendorPath(string $vendorPath): string {
        $normalized = rtrim(trim($vendorPath), '/');

        if ($normalized === '') {
            throw new InvalidArgumentException('The vendor path must not be empty. Pass the vendor directory as seen from the AGENTS.md file, for example app/vendor.');
        }

        return $normalized;
    }

    private static function append(string $content, string $block): string {
        if (trim($content) === '') {
            return $block . "\n";
        }

        return rtrim($content) . "\n\n" . $block . "\n";
    }
}
