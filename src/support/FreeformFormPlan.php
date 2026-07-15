<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use craft\helpers\StringHelper;
use Mcp\Exception\ToolCallException;

/**
 * Pure-logic planner for the create_form tool: decodes and validates the
 * form/field spec and maps the minimal v1 field-type keywords to their
 * Freeform field classes.
 *
 * Kept free of any Freeform or Craft-boot dependency (only the Craft
 * StringHelper static, which needs no application) so it is unit-testable in
 * isolation and loads safely when Freeform is absent. The Freeform field
 * classes are referenced only as string FQCNs — never imported — so nothing
 * here fatals without the plugin installed.
 *
 * @author 2RM
 */
final class FreeformFormPlan {
    /**
     * Minimal v1 field-type keyword => Freeform field class (string FQCN).
     */
    public const FIELD_TYPE_MAP = [
        'text' => 'Solspace\\Freeform\\Fields\\Implementations\\TextField',
        'textarea' => 'Solspace\\Freeform\\Fields\\Implementations\\TextareaField',
        'email' => 'Solspace\\Freeform\\Fields\\Implementations\\EmailField',
        'dropdown' => 'Solspace\\Freeform\\Fields\\Implementations\\DropdownField',
        'checkbox' => 'Solspace\\Freeform\\Fields\\Implementations\\CheckboxField',
        'number' => 'Solspace\\Freeform\\Fields\\Implementations\\NumberField',
    ];

    /** Field-type keywords that require/accept an options list. */
    public const OPTION_FIELD_TYPES = ['dropdown'];

    /**
     * Resolve the form handle: explicit param, or StringHelper::toHandle() of
     * the name (NOT camelCase — matches Craft's handle convention).
     *
     * @throws ToolCallException
     */
    public static function resolveFormHandle(?string $handle, string $name): string {
        $resolved = $handle !== null && trim($handle) !== ''
            ? trim($handle)
            : StringHelper::toHandle($name);

        self::assertValidHandle($resolved, 'Form');

        return $resolved;
    }

    /**
     * @throws ToolCallException
     */
    public static function assertValidHandle(string $handle, string $label): void {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $handle) === 1) {
            return;
        }

        throw new ToolCallException(
            "{$label} handle '{$handle}' is invalid: handles must start with a letter and contain only letters, digits and underscores.",
        );
    }

    /**
     * Decode and validate the fields JSON payload into normalized specs.
     *
     * @return array<int, array{label: string, handle: string, type: string, typeClass: string, required: bool, options: array<int, array{label: string, value: string}>}>
     * @throws ToolCallException
     */
    public static function decodeFields(string $json): array {
        $trimmed = trim($json);
        if ($trimmed === '') {
            throw new ToolCallException(
                'fields must be a JSON array of {label, type, handle?, required?, options?} objects.',
            );
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ToolCallException(
                'fields must be a JSON array of {label, type, handle?, required?, options?} objects.',
            );
        }

        if ($decoded === []) {
            throw new ToolCallException('fields must contain at least one field.');
        }

        return array_map(self::normalizeFieldSpec(...), $decoded, array_keys($decoded));
    }

    /**
     * @return array{label: string, handle: string, type: string, typeClass: string, required: bool, options: array<int, array{label: string, value: string}>}
     * @throws ToolCallException
     */
    public static function normalizeFieldSpec(mixed $spec, int $index): array {
        if (!is_array($spec)) {
            throw new ToolCallException("fields[{$index}] must be a JSON object.");
        }

        $label = $spec['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw new ToolCallException("fields[{$index}] requires a non-empty label.");
        }

        $type = $spec['type'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            throw new ToolCallException(
                "fields[{$index}] requires a type: one of " . implode(', ', array_keys(self::FIELD_TYPE_MAP)) . '.',
            );
        }

        $typeKey = strtolower(trim($type));
        $typeClass = self::resolveTypeClass($typeKey, $index);

        $handle = $spec['handle'] ?? null;
        if ($handle !== null && (!is_string($handle) || trim($handle) === '')) {
            throw new ToolCallException("fields[{$index}] handle must be a non-empty string when given.");
        }

        $resolvedHandle = is_string($handle) && trim($handle) !== '' ? trim($handle) : StringHelper::toHandle($label);
        self::assertValidHandle($resolvedHandle, "fields[{$index}]");

        return [
            'label' => trim($label),
            'handle' => $resolvedHandle,
            'type' => $typeKey,
            'typeClass' => $typeClass,
            'required' => (bool) ($spec['required'] ?? false),
            'options' => self::normalizeOptions($spec['options'] ?? null, $index, $typeKey),
        ];
    }

    /**
     * @throws ToolCallException
     */
    public static function resolveTypeClass(string $type, int $index): string {
        $class = self::FIELD_TYPE_MAP[$type] ?? null;
        if ($class === null) {
            throw new ToolCallException(
                "fields[{$index}] has unknown type '{$type}'. Supported types: "
                . implode(', ', array_keys(self::FIELD_TYPE_MAP)) . '.',
            );
        }

        return $class;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     * @throws ToolCallException
     */
    public static function normalizeOptions(mixed $options, int $index, string $type): array {
        if (in_array($type, self::OPTION_FIELD_TYPES, true) && (!is_array($options) || $options === [])) {
            throw new ToolCallException(
                "fields[{$index}]: {$type} fields require a non-empty options array of {label, value} objects.",
            );
        }

        if (!is_array($options)) {
            return [];
        }

        $list = array_values($options);

        return array_map(
            static fn (mixed $option): array => self::normalizeOption($option, $index),
            $list,
        );
    }

    /**
     * @return array{label: string, value: string}
     * @throws ToolCallException
     */
    private static function normalizeOption(mixed $option, int $index): array {
        if (!is_array($option) || !isset($option['label'], $option['value'])) {
            throw new ToolCallException("fields[{$index}]: each option must be a {label, value} object.");
        }

        return ['label' => (string) $option['label'], 'value' => (string) $option['value']];
    }

    /**
     * Build Freeform's optionConfiguration property for a choice field from a
     * normalized options list.
     *
     * @param array<int, array{label: string, value: string}> $options
     * @return array{source: string, useCustomValues: bool, options: array<int, array{label: string, value: string, optgroup: bool}>}
     */
    public static function optionConfiguration(array $options): array {
        return [
            'source' => 'custom',
            'useCustomValues' => true,
            'options' => array_map(
                static fn (array $option): array => [
                    'label' => $option['label'],
                    'value' => $option['value'],
                    'optgroup' => false,
                ],
                $options,
            ),
        ];
    }
}
