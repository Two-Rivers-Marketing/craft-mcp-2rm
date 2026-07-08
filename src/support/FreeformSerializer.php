<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use DateTimeInterface;
use RuntimeException;
use Throwable;
use Traversable;

/**
 * Serializes Freeform forms, form fields, and submissions into plain arrays
 * for MCP tool responses.
 *
 * Uses duck-typed property/method access so it never references Freeform
 * classes directly — the Freeform plugin (solspace/craft-freeform) may not
 * be installed in every environment this code is loaded in. Freeform's
 * public PHP API surface (FormsService, SubmissionsService, the Submission
 * element) is documented, but the shape of a form's layout/notifications/
 * integrations settings is not part of that public contract and has shifted
 * across Freeform major versions — every read here degrades to null/empty
 * rather than throwing when a given accessor is absent.
 *
 * @author 2RM
 */
final class FreeformSerializer {
    /**
     * Serialize a Freeform form summary for list_forms: id, handle, name.
     * Submission counts are added separately by the caller (a query is
     * needed per form; the serializer stays a pure duck-typed reader).
     *
     * @return array<string, mixed>
     */
    public static function formSummary(object $form): array {
        return [
            'id' => self::readProp($form, 'id'),
            'handle' => self::readProp($form, 'handle'),
            'name' => self::readProp($form, 'name'),
        ];
    }

    /**
     * Serialize a full form: identity, field layout, notifications, element
     * connections, and spam-protection settings.
     *
     * @return array<string, mixed>
     */
    public static function form(object $form): array {
        return [
            'id' => self::readProp($form, 'id'),
            'handle' => self::readProp($form, 'handle'),
            'name' => self::readProp($form, 'name'),
            'fields' => self::fieldLayout($form),
            'notifications' => self::sectionAttributes($form, ['notif']),
            'connections' => self::sectionAttributes($form, ['connect', 'integration', 'element']),
            'spamSettings' => self::sectionAttributes($form, ['spam', 'honeypot', 'captcha', 'duplicate', 'blocklist']),
        ];
    }

    /**
     * Serialize a form's field layout: handle, type, label, required per field.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fieldLayout(object $form): array {
        return array_values(array_map(
            self::fieldSummary(...),
            self::formFields($form),
        ));
    }

    /**
     * Collect the field-like objects exposed by a form, across the layout
     * shapes seen in different Freeform versions (Layout::getFields(),
     * Form::getFields(), or the form itself being iterable).
     *
     * @return array<int, object>
     */
    private static function formFields(object $form): array {
        $layout = self::readMethod($form, 'getLayout');
        $viaLayout = is_object($layout) ? self::readMethod($layout, 'getFields') : null;
        if (is_iterable($viaLayout)) {
            return self::iterableToList($viaLayout);
        }

        $viaForm = self::readMethod($form, 'getFields');
        if (is_iterable($viaForm)) {
            return self::iterableToList($viaForm);
        }

        if ($form instanceof Traversable) {
            return self::iterableToList($form);
        }

        return [];
    }

    /**
     * @return array<int, object>
     */
    private static function iterableToList(iterable $items): array {
        $out = [];
        foreach ($items as $item) {
            if (is_object($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Serialize a single Freeform field-like object.
     *
     * @return array<string, mixed>
     */
    public static function fieldSummary(object $field): array {
        return [
            'handle' => self::readProp($field, 'handle'),
            'type' => self::fieldType($field),
            'label' => self::readProp($field, 'label'),
            'required' => (bool) self::readProp($field, 'required'),
        ];
    }

    private static function fieldType(object $field): string {
        $type = self::readMethod($field, 'getType');
        if (is_string($type) && $type !== '') {
            return $type;
        }

        return $field::class;
    }

    /**
     * Serialize a submission summary: id, form, title/date, status.
     *
     * @return array<string, mixed>
     */
    public static function submissionSummary(object $submission): array {
        return [
            'id' => self::readProp($submission, 'id'),
            'formId' => self::readProp($submission, 'formId'),
            'title' => self::readProp($submission, 'title'),
            'status' => self::submissionStatus($submission),
            'dateCreated' => self::dateString(self::readProp($submission, 'dateCreated')),
        ];
    }

    private static function submissionStatus(object $submission): mixed {
        $status = self::readMethod($submission, 'getStatus');
        if (is_object($status)) {
            return self::readProp($status, 'handle') ?? self::readProp($status, 'name');
        }

        if ($status !== null) {
            return $status;
        }

        return self::readProp($submission, 'status');
    }

    private static function dateString(mixed $date): ?string {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d H:i:s');
        }

        return is_string($date) ? $date : null;
    }

    /**
     * Read a submission's field values for the given handles, coerced to
     * JSON-safe values.
     *
     * @param array<int, string> $handles
     * @return array<string, mixed>
     */
    public static function submissionFieldValues(object $submission, array $handles): array {
        $values = [];
        foreach ($handles as $handle) {
            $values[$handle] = self::submissionFieldValue($submission, $handle);
        }

        return $values;
    }

    private static function submissionFieldValue(object $submission, string $handle): mixed {
        if (method_exists($submission, 'getFieldValue')) {
            try {
                return self::scalarize($submission->getFieldValue($handle));
            } catch (Throwable) {
                return null;
            }
        }

        return self::scalarize(self::readProp($submission, $handle));
    }

    /**
     * Build a CSV document (header row + data rows) from an array of
     * associative rows. Missing keys become empty cells; extra keys not in
     * $headers are dropped. Pure logic — no Freeform/Craft dependency.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $headers
     */
    public static function toCsv(array $rows, array $headers): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open in-memory stream for CSV export.');
        }

        fputcsv($stream, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, self::csvRow($row, $headers), ',', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private static function csvRow(array $row, array $headers): array {
        return array_map(
            static fn (string $header): string => self::csvCell($row[$header] ?? null),
            $headers,
        );
    }

    private static function csvCell(mixed $value): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Read a filtered slice of an object's public attributes, matched by
     * keyword substring against the attribute name. Used for
     * notifications/connections/spam-settings sections whose shape is not
     * part of Freeform's stable public API — returns null when nothing about
     * the object is introspectable.
     *
     * @param array<int, string> $keywords
     * @return array<string, mixed>|null
     */
    public static function sectionAttributes(object $obj, array $keywords): ?array {
        $all = self::attributesOf($obj);
        if ($all === null) {
            return null;
        }

        $filtered = [];
        foreach ($all as $key => $value) {
            if (!self::matchesAnyKeyword($key, $keywords)) {
                continue;
            }

            $filtered[$key] = self::scalarize($value);
        }

        return $filtered;
    }

    private static function matchesAnyKeyword(string $key, array $keywords): bool {
        $lower = strtolower($key);
        foreach ($keywords as $keyword) {
            if (str_contains($lower, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attributesOf(object $obj): ?array {
        $viaToArray = self::readMethod($obj, 'toArray');
        if (is_array($viaToArray)) {
            return $viaToArray;
        }

        $viaGetAttributes = self::readMethod($obj, 'getAttributes');
        if (is_array($viaGetAttributes)) {
            return $viaGetAttributes;
        }

        $vars = get_object_vars($obj);

        return $vars === [] ? null : $vars;
    }

    /**
     * Reduce a value to something JSON-safe: scalars/null pass through,
     * arrays are recursively reduced, stringable objects become strings, and
     * anything else becomes a placeholder rather than risking a serialization
     * failure or leaking an internal object graph.
     */
    private static function scalarize(mixed $value): mixed {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(self::scalarize(...), $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '(complex value)';
    }

    /**
     * Call a zero-arg method if it exists, swallowing failures. Returns null
     * for absent methods or thrown exceptions.
     */
    private static function readMethod(object $obj, string $method): mixed {
        if (!method_exists($obj, $method)) {
            return null;
        }

        try {
            return $obj->$method();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read a property from an object safely: declared public properties
     * first, then getters, then Yii magic properties. Missing properties
     * yield null.
     */
    public static function readProp(object $object, string $name): mixed {
        if (property_exists($object, $name)) {
            return $object->$name ?? null;
        }

        $getter = 'get' . ucfirst($name);
        if (method_exists($object, $getter)) {
            return self::readMethod($object, $getter);
        }

        if (method_exists($object, 'canGetProperty') && $object->canGetProperty($name)) {
            return $object->$name ?? null;
        }

        return null;
    }
}
