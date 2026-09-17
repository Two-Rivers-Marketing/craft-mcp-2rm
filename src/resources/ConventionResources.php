<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\resources;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use twoRivers\craft\Mcp\attributes\McpResourceMeta;
use twoRivers\craft\Mcp\enums\ResourceCategory;
use twoRivers\craft\Mcp\support\SafeResourceExecution;

/**
 * MCP resources for build conventions shipped with the plugin.
 *
 * Serves markdown docs from docs/conventions/ as MCP resources so agents
 * can discover and read project-level building patterns at runtime.
 *
 * @author 2RM
 */
final class ConventionResources {
    private const CONVENTIONS_DIR = __DIR__ . '/../../docs/conventions';

    /**
     * List all available conventions with their frontmatter metadata.
     *
     * @return array{conventions: list<array{topic: string, title: string, description: string, tags: list<string>}>}
     */
    #[McpResource(
        uri: 'craft://conventions',
        name: 'all-conventions',
        description: 'Skimmable index of all build conventions: topic, title, description, and tags parsed from each doc.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONVENTION)]
    public function allConventions(): array {
        return SafeResourceExecution::run(function (): array {
            $dir = realpath(self::CONVENTIONS_DIR);

            if ($dir === false || !is_dir($dir)) {
                return ['conventions' => []];
            }

            $files = glob($dir . '/*.md');

            if ($files === false) {
                return ['conventions' => []];
            }

            $conventions = [];

            foreach ($files as $file) {
                $topic = basename($file, '.md');
                $content = (string) file_get_contents($file);
                $meta = self::parseFrontmatter($content);

                $conventions[] = [
                    'topic' => $topic,
                    'title' => $meta['title'] ?? $topic,
                    'description' => $meta['description'] ?? '',
                    'tags' => $meta['tags'] ?? [],
                ];
            }

            return ['conventions' => $conventions];
        });
    }

    /**
     * Read the full markdown content of a specific convention doc.
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://conventions/{topic}',
        name: 'convention-detail',
        description: 'Full markdown content of a specific build convention document.',
        mimeType: 'text/markdown',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONVENTION)]
    public function conventionDetail(string $topic): string {
        return SafeResourceExecution::run(function () use ($topic): string {
            $dir = realpath(self::CONVENTIONS_DIR);

            if ($dir === false || !is_dir($dir)) {
                throw new ResourceReadException("Conventions directory not found.");
            }

            $safe = basename($topic);
            $path = $dir . '/' . $safe . '.md';

            if (!is_file($path)) {
                throw new ResourceReadException("Convention '{$safe}' not found.");
            }

            return (string) file_get_contents($path);
        });
    }

    /**
     * Parse OKF YAML frontmatter from a markdown file's content.
     *
     * @return array{title?: string, description?: string, tags?: list<string>}
     */
    private static function parseFrontmatter(string $content): array {
        if (preg_match('/\A---\n(.+?)\n---/s', $content, $m) !== 1) {
            return [];
        }

        $block = $m[1];
        $meta = [];

        if (preg_match('/^title:\s*(.+)$/m', $block, $t) === 1) {
            $meta['title'] = trim($t[1]);
        }

        if (preg_match('/^description:\s*(.+)$/m', $block, $d) === 1) {
            $meta['description'] = trim($d[1]);
        }

        if (preg_match('/^tags:\s*\[(.+)]$/m', $block, $tags) === 1) {
            $meta['tags'] = array_map(
                static fn (string $tag): string => trim($tag),
                explode(',', $tags[1]),
            );
        }

        return $meta;
    }
}
