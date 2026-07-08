<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

/**
 * Serializes Neo content-builder structures (block types, field layouts,
 * field options) into plain arrays for MCP tool responses.
 *
 * Uses duck-typed property/method access so it never references Neo classes
 * directly — the Neo plugin (benf/craft-neo) may not be installed in every
 * environment this code is loaded in.
 *
 * @author 2RM
 */
final class NeoSerializer {
    /**
     * Serialize a Neo block type (benf\neo\models\BlockType) to an array.
     *
     * @param object $blockType The Neo BlockType model (duck-typed)
     * @param bool|null $templateExists Whether a matching body_blocks template exists
     * @param string|null $templatePath Site-relative template path that was checked
     * @return array<string, mixed>
     */
    public static function blockType(object $blockType, ?bool $templateExists = null, ?string $templatePath = null): array {
        $result = [
            'handle' => self::prop($blockType, 'handle'),
            'name' => self::prop($blockType, 'name'),
            'description' => self::prop($blockType, 'description'),
            'enabled' => self::prop($blockType, 'enabled'),
            'nesting' => self::nesting($blockType),
            'fields' => self::fieldLayoutFields(self::layoutOf($blockType)),
        ];

        if ($templatePath !== null) {
            $result['template'] = [
                'path' => $templatePath,
                'exists' => (bool) $templateExists,
            ];
        }

        return $result;
    }

    /**
     * Extract nesting rules from a Neo block type.
     *
     * @return array<string, mixed>
     */
    public static function nesting(object $blockType): array {
        return [
            'topLevel' => self::prop($blockType, 'topLevel'),
            'childBlocks' => self::prop($blockType, 'childBlocks'),
            'minBlocks' => self::prop($blockType, 'minBlocks'),
            'maxBlocks' => self::prop($blockType, 'maxBlocks'),
            'minChildBlocks' => self::prop($blockType, 'minChildBlocks'),
            'maxChildBlocks' => self::prop($blockType, 'maxChildBlocks'),
            'minSiblingBlocks' => self::prop($blockType, 'minSiblingBlocks'),
            'maxSiblingBlocks' => self::prop($blockType, 'maxSiblingBlocks'),
            'groupChildBlockTypes' => self::prop($blockType, 'groupChildBlockTypes'),
        ];
    }

    /**
     * Serialize all custom fields of a field layout, including required flags.
     *
     * @param object|null $fieldLayout A craft\models\FieldLayout (duck-typed)
     * @return array<int, array<string, mixed>>
     */
    public static function fieldLayoutFields(?object $fieldLayout): array {
        if ($fieldLayout === null) {
            return [];
        }

        // Craft 4/5: layout elements carry the `required` flag
        if (method_exists($fieldLayout, 'getCustomFieldElements')) {
            /** @phpstan-ignore-next-line duck-typed call */
            $elements = $fieldLayout->getCustomFieldElements();

            return array_values(array_map(
                fn (object $element): array => self::field(
                    $element->getField(),
                    (bool) self::prop($element, 'required'),
                ),
                $elements,
            ));
        }

        if (method_exists($fieldLayout, 'getCustomFields')) {
            /** @phpstan-ignore-next-line duck-typed call */
            $fields = $fieldLayout->getCustomFields();

            return array_values(array_map(
                fn (object $field): array => self::field($field, false),
                $fields,
            ));
        }

        return [];
    }

    /**
     * Serialize a single field to handle/name/type/required (+ options when applicable).
     *
     * @return array<string, mixed>
     */
    public static function field(object $field, bool $required = false): array {
        $result = [
            'handle' => self::prop($field, 'handle'),
            'name' => self::prop($field, 'name'),
            'type' => $field::class,
            'required' => $required,
            'instructions' => self::prop($field, 'instructions'),
        ];

        $options = self::fieldOptions($field);
        if ($options !== null) {
            $result['options'] = $options;
        }

        return $result;
    }

    /**
     * Extract valid option values from a field.
     *
     * Options-based fields (Dropdown, RadioButtons, Checkboxes, MultiSelect)
     * expose an `options` array of {label, value, default}. Lightswitch fields
     * have implicit true/false values with on/off labels. Other field types
     * return null (no fixed option set).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function fieldOptions(object $field): ?array {
        if (self::isLightswitch($field)) {
            return [
                ['value' => true, 'label' => (string) (self::prop($field, 'onLabel') ?? 'On'), 'default' => (bool) self::prop($field, 'default')],
                ['value' => false, 'label' => (string) (self::prop($field, 'offLabel') ?? 'Off'), 'default' => !self::prop($field, 'default')],
            ];
        }

        $options = self::prop($field, 'options');
        if (!is_array($options)) {
            return null;
        }

        $result = [];
        foreach ($options as $option) {
            $normalized = self::normalizeOption($option);
            if ($normalized === null) {
                continue;
            }

            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * Normalize a single option entry. Optgroup headers (no selectable value)
     * and unrecognized shapes return null.
     *
     * @return array<string, mixed>|null
     */
    private static function normalizeOption(mixed $option): ?array {
        if (is_string($option)) {
            return ['value' => $option, 'label' => $option, 'default' => false];
        }

        if (!is_array($option) || array_key_exists('optgroup', $option)) {
            return null;
        }

        return [
            'value' => $option['value'] ?? null,
            'label' => $option['label'] ?? null,
            'default' => (bool) ($option['default'] ?? false),
        ];
    }

    private static function isLightswitch(object $field): bool {
        return str_ends_with($field::class, 'Lightswitch');
    }

    private static function layoutOf(object $blockType): ?object {
        if (!method_exists($blockType, 'getFieldLayout')) {
            return null;
        }

        /** @phpstan-ignore-next-line duck-typed call */
        $layout = $blockType->getFieldLayout();

        return is_object($layout) ? $layout : null;
    }

    /**
     * Read a property from an object safely: declared public properties first,
     * then getters, then Yii magic properties. Missing properties yield null.
     */
    private static function prop(object $object, string $name): mixed {
        if (property_exists($object, $name)) {
            return $object->$name ?? null;
        }

        $getter = 'get' . ucfirst($name);
        if (method_exists($object, $getter)) {
            return $object->$getter();
        }

        if (method_exists($object, 'canGetProperty') && $object->canGetProperty($name)) {
            return $object->$name ?? null;
        }

        return null;
    }
}
