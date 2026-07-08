<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use benf\neo\Field as NeoField;
use Craft;
use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\Mcp;

/**
 * Shared Neo builder-field resolution for Neo tool classes.
 *
 * Handle precedence: explicit fieldHandle param > builderFieldHandle plugin
 * setting (default 'contentBuilder') > sole Neo field on the entry's layout.
 *
 * Consumers must implement ConditionalToolProvider (isAvailable()); the Neo
 * class references here resolve lazily, so loading this trait is safe when
 * the Neo plugin (benf/craft-neo) is absent.
 *
 * @author 2RM
 */
trait ResolvesNeoBuilderField {
    /**
     * Resolve the Neo builder field from an explicit handle, an entry, or the
     * builderFieldHandle plugin setting (default 'contentBuilder').
     *
     * @throws ToolCallException
     */
    private function resolveBuilderField(?int $entryId, ?string $fieldHandle): NeoField {
        $handle = $fieldHandle ?? Mcp::settings()->builderFieldHandle;

        if ($entryId !== null) {
            return $this->resolveFieldFromEntry($entryId, $handle, $fieldHandle !== null);
        }

        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if ($field === null) {
            throw new ToolCallException(
                "Field '{$handle}' not found. Pass fieldHandle explicitly or configure the builderFieldHandle plugin setting.",
            );
        }

        if (!$field instanceof NeoField) {
            throw new ToolCallException(
                "Field '{$handle}' is not a Neo field (" . $field::class . ').',
            );
        }

        return $field;
    }

    /**
     * Resolve the Neo field from an entry's field layout.
     *
     * Prefers the field matching $handle; when no explicit handle was given
     * and the layout has exactly one Neo field, that field is used.
     *
     * @throws ToolCallException
     */
    private function resolveFieldFromEntry(int $entryId, string $handle, bool $explicitHandle): NeoField {
        $entry = Craft::$app->getElements()->getElementById($entryId);

        if ($entry === null) {
            throw new ToolCallException("Element with ID {$entryId} not found");
        }

        $neoFields = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof NeoField) {
                continue;
            }

            if ($field->handle === $handle) {
                return $field;
            }

            $neoFields[] = $field;
        }

        if ($explicitHandle) {
            throw new ToolCallException("Entry {$entryId} has no Neo field with handle '{$handle}'");
        }

        if (count($neoFields) === 1) {
            return $neoFields[0];
        }

        if ($neoFields === []) {
            throw new ToolCallException("Entry {$entryId} has no Neo fields in its field layout");
        }

        $handles = implode(', ', array_map(
            static fn (NeoField $field): string => (string) $field->handle,
            $neoFields,
        ));

        throw new ToolCallException(
            "Entry {$entryId} has multiple Neo fields ({$handles}); pass fieldHandle to choose one",
        );
    }

    /**
     * Assert Neo is available, throw exception if not.
     *
     * @throws ToolCallException
     */
    private function assertNeoAvailable(): void {
        if (!static::isAvailable()) {
            throw new ToolCallException('The Neo plugin (benf/craft-neo) is not installed or not enabled');
        }
    }
}
