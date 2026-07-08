<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

/**
 * Renders body_blocks template stubs for scaffolded Neo block types.
 *
 * Pure string generation — no Craft or Neo dependencies — so stub output is
 * fully unit-testable. The default stub follows the 2RM body-block pattern:
 * extends the body-block-open layout, wraps content in a main-content
 * container, hints every attached field as a Twig comment, and (for container
 * blocks) delegates children to the columnItem include.
 *
 * Custom stub templates (Settings::$templateStubPath) may embed these tokens:
 * - __BLOCK_HANDLE__   the block type handle
 * - __FIELD_HINTS__    the commented field-variable hint lines
 * - __CHILDREN_LOOP__  the block.children loop (empty string when the block
 *                      type has no child block types)
 *
 * @author 2RM
 */
final class BlockTypeStub {
    public const TOKEN_HANDLE = '__BLOCK_HANDLE__';

    public const TOKEN_FIELD_HINTS = '__FIELD_HINTS__';

    public const TOKEN_CHILDREN_LOOP = '__CHILDREN_LOOP__';

    /**
     * Render the template stub for a block type.
     *
     * @param string $handle The block type handle (also the template basename)
     * @param array<int, string> $fieldHandles Field handles to hint as {# block.<handle> #} comments
     * @param array<int, string> $childBlockTypes Child block type handles; non-empty adds a children loop
     * @param string|null $template Custom stub template (see class doc for tokens); null = built-in stub
     */
    public static function render(
        string $handle,
        array $fieldHandles,
        array $childBlockTypes = [],
        ?string $template = null,
    ): string {
        $hints = self::fieldHints($fieldHandles);
        $childrenLoop = $childBlockTypes === [] ? '' : self::childrenLoop($childBlockTypes);

        if ($template !== null) {
            return strtr($template, [
                self::TOKEN_HANDLE => $handle,
                self::TOKEN_FIELD_HINTS => $hints,
                self::TOKEN_CHILDREN_LOOP => $childrenLoop,
            ]);
        }

        return self::defaultStub($handle, $hints, $childrenLoop);
    }

    /**
     * Build the commented field-variable hint lines.
     *
     * @param array<int, string> $fieldHandles
     */
    private static function fieldHints(array $fieldHandles): string {
        $lines = array_map(
            static fn (string $fieldHandle): string => "    {# block.{$fieldHandle} #}",
            $fieldHandles,
        );

        return implode("\n", $lines);
    }

    /**
     * Build the block.children loop that delegates to the columnItem include.
     *
     * @param array<int, string> $childBlockTypes
     */
    private static function childrenLoop(array $childBlockTypes): string {
        $childTypes = implode(', ', $childBlockTypes);

        return <<<TWIG
                {# child block types: {$childTypes} #}
                {% set items = block.children.all() %}
                <div class="row">
                    {% for item in items %}
                        {% include [globalPaths[0] ~ 'columnItem', globalPaths[1] ~ 'columnItem'] with {
                            entry: item,
                            item: item,
                            parentBlock: block,
                            loopIndex: loop.index,
                            columnItemPaths: columnItemPaths
                        } %}
                    {% endfor %}
                </div>
            TWIG;
    }

    /**
     * Assemble the built-in default stub.
     */
    private static function defaultStub(string $handle, string $hints, string $childrenLoop): string {
        $body = self::joinBlocks($hints, $childrenLoop);

        return <<<TWIG
            {# body_blocks/{$handle}.twig — scaffolded by craft-mcp create_block_type #}
            {% extends "global/_includes/body-block-open" %}
            {% block content %}

            <div class="main-content {{ containerClass }}">
            {$body}
            </div>

            {% endblock %}

            TWIG;
    }

    /**
     * Join the hint and children-loop sections, dropping empty parts.
     */
    private static function joinBlocks(string $hints, string $childrenLoop): string {
        $parts = array_filter([$hints, $childrenLoop], static fn (string $part): bool => $part !== '');

        return implode("\n\n", $parts);
    }
}
