<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use DateTimeInterface;
use ReflectionProperty;
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
     * The three settings sections are not readable from the form object alone
     * in Freeform 5 — notifications live in NotificationsService and
     * connections/spam are form "integrations" (see IntegrationsService).
     * The caller (FreeformTools::getForm) resolves those against the live
     * services and passes the raw objects in; this method only shapes them,
     * so it stays a pure duck-typed reader with no Freeform dependency.
     *
     * @param array<int, object> $notifications form-specific notification template records
     * @param array<int, object> $connections   "elements" integrations that map a submission to a Craft element
     * @param array<int, object> $spam          captcha / spam-blocking integrations
     * @return array<string, mixed>
     */
    public static function form(
        object $form,
        array $notifications = [],
        array $connections = [],
        array $spam = [],
    ): array {
        return [
            'id' => self::readProp($form, 'id'),
            'handle' => self::readProp($form, 'handle'),
            'name' => self::readProp($form, 'name'),
            'fields' => self::fieldLayout($form),
            'notifications' => array_map(self::notification(...), array_values($notifications)),
            'connections' => array_map(self::integration(...), array_values($connections)),
            'spamSettings' => array_map(self::integration(...), array_values($spam)),
        ];
    }

    /**
     * Serialize a Freeform notification template record (the email
     * notification config attached to a form).
     *
     * @return array<string, mixed>
     */
    public static function notification(object $record): array {
        return [
            'id' => self::readProp($record, 'id'),
            'handle' => self::readProp($record, 'handle'),
            'name' => self::readProp($record, 'name'),
            'formId' => self::readProp($record, 'formId'),
            'subject' => self::readProp($record, 'subject'),
            'fromName' => self::readProp($record, 'fromName'),
            'fromEmail' => self::readProp($record, 'fromEmail'),
            'replyToEmail' => self::readProp($record, 'replyToEmail'),
            'cc' => self::scalarize(self::readProp($record, 'cc')),
            'bcc' => self::scalarize(self::readProp($record, 'bcc')),
        ];
    }

    /**
     * Serialize a Freeform form integration (element connection, captcha, or
     * spam-blocking). `type` is the integration category (e.g. 'elements',
     * 'captchas', 'spam-blocking'); `enabled` says whether it runs on submit.
     *
     * @return array<string, mixed>
     */
    public static function integration(object $integration): array {
        return [
            'id' => self::readProp($integration, 'id'),
            'handle' => self::readProp($integration, 'handle'),
            'name' => self::readProp($integration, 'name'),
            'type' => self::integrationType($integration),
            'enabled' => (bool) self::readProp($integration, 'enabled'),
        ];
    }

    private static function integrationType(object $integration): ?string {
        $definition = self::readMethod($integration, 'getTypeDefinition');
        if (!is_object($definition)) {
            return null;
        }

        $type = self::readProp($definition, 'type');

        return is_string($type) ? $type : null;
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
        // Freeform 5: $submission->{handle} yields the field object; its stored
        // value comes from getValue(). getFieldValue($handle) is NOT the value
        // accessor here (it throws "Invalid field handle"), so it is only a
        // last-resort fallback for other Freeform versions.
        $field = self::readDynamicProp($submission, $handle);
        if (is_object($field) && method_exists($field, 'getValue')) {
            return self::scalarize(self::readMethod($field, 'getValue'));
        }

        if ($field !== null && !is_object($field)) {
            return self::scalarize($field);
        }

        if (method_exists($submission, 'getFieldValue')) {
            try {
                return self::scalarize($submission->getFieldValue($handle));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Read a value that a class exposes only through a magic getter (e.g. a
     * Freeform submission's per-field-handle access), swallowing failures.
     */
    private static function readDynamicProp(object $object, string $name): mixed {
        if (!property_exists($object, $name) && !method_exists($object, '__get')) {
            return null;
        }

        try {
            return $object->{$name};
        } catch (Throwable) {
            return null;
        }
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
        // Only read a declared property directly when it is public; non-public
        // properties (e.g. Freeform field $handle/$label) must go through their
        // getter, or a bare $object->$name would hit __get / an access error.
        if (property_exists($object, $name) && (new ReflectionProperty($object, $name))->isPublic()) {
            return $object->$name ?? null;
        }

        $getter = 'get' . ucfirst($name);
        if (method_exists($object, $getter)) {
            return self::readMethod($object, $getter);
        }

        // Boolean-style accessor (e.g. isRequired() for a "required" prop).
        $isser = 'is' . ucfirst($name);
        if (method_exists($object, $isser)) {
            return self::readMethod($object, $isser);
        }

        if (method_exists($object, 'canGetProperty') && $object->canGetProperty($name)) {
            return $object->$name ?? null;
        }

        return null;
    }
}
