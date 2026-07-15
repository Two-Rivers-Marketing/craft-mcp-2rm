<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use craft\helpers\StringHelper;
use Mcp\Exception\ToolCallException;

/**
 * Pure-logic planner for the create_form / update_form tools: decodes and
 * validates the form/field spec, maps the minimal v1 field-type keywords to
 * their Freeform field classes, and (for update_form) diffs a desired field
 * list against a form's current fields to decide which get added, kept
 * (reusing their identity so stored submission data survives), removed, or
 * left untouched.
 *
 * Kept free of any Freeform or Craft-boot dependency (only the Craft
 * StringHelper static, which needs no application) so it is unit-testable in
 * isolation and loads safely when Freeform is absent. The Freeform field
 * classes are referenced only as string FQCNs — never imported — so nothing
 * here fatals without the plugin installed. UID generation for new fields/
 * rows is NOT done here (StringHelper::UUID() needs no Craft app, but keeping
 * it out of this class keeps the diff algorithm itself pure and independent
 * of identifier generation) — the caller (FreeformScaffoldTools) assigns real
 * UUIDs for entries this class marks `isNew`.
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

    /**
     * Reverse-lookup a stored Freeform field typeClass to its v1 keyword.
     * Null means the field's type is outside the v1-supported subset (e.g. a
     * file upload, signature, group, or table field built in the control
     * panel) — update_form always leaves such fields untouched (see
     * planFieldChanges()) rather than risk misrepresenting or dropping a
     * field type it does not understand.
     */
    public static function resolveExistingType(string $typeClass): ?string {
        $found = array_search($typeClass, self::FIELD_TYPE_MAP, true);

        return $found === false ? null : $found;
    }

    /**
     * Diff a desired field-spec list (from decodeFields()) against a form's
     * current fields to plan an update_form save.
     *
     * Matching is by handle, restricted to v1-supported existing fields:
     * a spec whose handle matches a current supported field is "kept" (its
     * existing uid/rowUid are reused, so Freeform updates the same
     * FormFieldRecord in place — same DB id, same/renamed submission-content
     * column — instead of deleting-then-recreating it, which is what
     * preserves stored submission data across the edit); a spec with no
     * match is "new"; a supported existing field whose handle is absent from
     * specs is "removed". Fields whose current type is outside the v1 subset
     * are never matched, never counted as removed, and always pass through
     * unchanged in `preserved` — the caller must persist them exactly as
     * given to avoid destroying complex CP-built fields this class doesn't
     * understand.
     *
     * Row identity is preserved for kept fields (so a field sharing a row
     * with another field in the control panel keeps that grouping); new
     * fields each get their own new row. `conflicts` lists any rowUid shared
     * between a kept field and a preserved (unsupported) field — the caller
     * should refuse the update in that case, since this class cannot safely
     * plan a shared row it partly does not understand.
     *
     * @param array<int, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>, supported:bool}> $existingFields
     * @param array<int, array{label:string, handle:string, type:string, typeClass:string, required:bool, options:array<int, array{label:string, value:string}>}> $specs
     * @return array{
     *     managed: array<int, array{spec: array<string, mixed>, isNew: bool, existingUid: ?string, existingRowUid: ?string, rowKey: string, rowOrder: int, fieldOrder: int}>,
     *     preserved: array<int, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>}>,
     *     removed: array<int, array{handle:string, uid:string}>,
     *     conflicts: array<int, string>,
     * }
     */
    public static function planFieldChanges(array $existingFields, array $specs): array {
        $supportedExisting = array_values(array_filter(
            $existingFields,
            static fn (array $field): bool => $field['supported'],
        ));
        $existingByHandle = array_column($supportedExisting, null, 'handle');
        $specHandles = array_column($specs, 'handle');

        $matched = array_map(
            static fn (array $spec, int $index): array => self::matchSpecToExisting($spec, $index, $existingByHandle),
            $specs,
            array_keys($specs),
        );
        $managed = self::assignRowOrders($matched);

        $removed = array_values(array_filter(
            $supportedExisting,
            static fn (array $field): bool => !in_array($field['handle'], $specHandles, true),
        ));

        $preservedSource = array_values(array_filter(
            $existingFields,
            static fn (array $field): bool => !$field['supported'],
        ));
        $preserved = self::offsetPreservedRowOrders($preservedSource, self::distinctRowCount($managed));

        $keptRowUids = array_column(
            array_filter($matched, static fn (array $item): bool => !$item['isNew']),
            'existingRowUid',
        );
        $conflicts = array_values(array_intersect(
            array_unique(array_column($preservedSource, 'rowUid')),
            array_unique($keptRowUids),
        ));

        return [
            'managed' => $managed,
            'preserved' => $preserved,
            'removed' => array_map(
                static fn (array $field): array => ['handle' => $field['handle'], 'uid' => $field['uid']],
                $removed,
            ),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>, supported:bool}> $existingByHandle
     * @return array{spec: array<string, mixed>, isNew: bool, existingUid: ?string, existingRowUid: ?string, rowKey: string}
     */
    private static function matchSpecToExisting(array $spec, int $index, array $existingByHandle): array {
        $existing = $existingByHandle[$spec['handle']] ?? null;

        return [
            'spec' => $spec,
            'isNew' => $existing === null,
            'existingUid' => $existing['uid'] ?? null,
            'existingRowUid' => $existing['rowUid'] ?? null,
            'rowKey' => $existing['rowUid'] ?? ('__new__' . $index),
        ];
    }

    /**
     * Assign each matched spec a row order (position among distinct rows, in
     * first-seen order) and a field order (position within its row), so
     * fields sharing a rowKey (an existing shared row, kept together) get
     * sequential in-row order instead of colliding on 0.
     *
     * @param array<int, array{spec: array<string, mixed>, isNew: bool, existingUid: ?string, existingRowUid: ?string, rowKey: string}> $matched
     * @return array<int, array{spec: array<string, mixed>, isNew: bool, existingUid: ?string, existingRowUid: ?string, rowKey: string, rowOrder: int, fieldOrder: int}>
     */
    private static function assignRowOrders(array $matched): array {
        $rowOrders = [];
        $rowFieldCounts = [];
        $result = [];

        foreach ($matched as $item) {
            $rowKey = $item['rowKey'];
            if (!array_key_exists($rowKey, $rowOrders)) {
                $rowOrders[$rowKey] = count($rowOrders);
            }

            $fieldOrder = $rowFieldCounts[$rowKey] ?? 0;
            $rowFieldCounts[$rowKey] = $fieldOrder + 1;

            $result[] = [...$item, 'rowOrder' => $rowOrders[$rowKey], 'fieldOrder' => $fieldOrder];
        }

        return $result;
    }

    /**
     * @param array<int, array{rowKey: string}> $managed
     */
    private static function distinctRowCount(array $managed): int {
        return count(array_unique(array_column($managed, 'rowKey')));
    }

    /**
     * Preserved (unsupported-type) fields keep their existing row/field order
     * relative to each other untouched, but their row order is offset to
     * start after every managed row so they never collide with a kept/new
     * row's order value.
     *
     * @param array<int, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>, supported:bool}> $preservedSource
     * @return array<int, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>}>
     */
    private static function offsetPreservedRowOrders(array $preservedSource, int $offset): array {
        $sorted = $preservedSource;
        usort($sorted, static fn (array $a, array $b): int => $a['rowOrder'] <=> $b['rowOrder']);

        $rowOrderMap = [];
        $result = [];

        foreach ($sorted as $field) {
            $rowUid = $field['rowUid'];
            if (!array_key_exists($rowUid, $rowOrderMap)) {
                $rowOrderMap[$rowUid] = $offset + count($rowOrderMap);
            }

            $result[] = [...$field, 'rowOrder' => $rowOrderMap[$rowUid]];
        }

        return $result;
    }
}
