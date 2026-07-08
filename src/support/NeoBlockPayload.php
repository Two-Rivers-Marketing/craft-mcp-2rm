<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Pure-logic helpers for Neo block write payloads: decoding the `fields`
 * JSON argument, validating field handles against a block type's layout,
 * and building before/after diffs for dry-run previews.
 *
 * No Craft or Neo dependencies — fully unit-testable. Duck-typed where it
 * touches Neo block elements (the Neo plugin may not be installed).
 *
 * Extension points for later issues:
 * - #9 (children trees + positioning): summarizeBlock() already carries
 *   `level`; diff() appends at the end — extend with an insert index /
 *   parent path when positioning lands.
 * - #10 (update/reorder/delete): diff() takes plain before/after summary
 *   lists, so mutations other than append can reuse it by passing the
 *   mutated list as a new builder method.
 *
 * @author 2RM
 */
final class NeoBlockPayload {
    /**
     * Decode the `fields` JSON argument into a fieldHandle => value map.
     *
     * @return array<string, mixed>
     * @throws ToolCallException When the JSON is invalid or not an object
     */
    public static function decode(?string $fieldsJson): array {
        if ($fieldsJson === null || trim($fieldsJson) === '') {
            return [];
        }

        $decoded = json_decode($fieldsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ToolCallException('Invalid JSON in fields parameter: ' . json_last_error_msg());
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ToolCallException(
                'fields must be a JSON object of fieldHandle => value pairs, e.g. {"heading": "Hello"}',
            );
        }

        return $decoded;
    }

    /**
     * Return field handles present in the payload but not in the allowed set.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string> $allowedHandles
     * @return array<int, string>
     */
    public static function unknownHandles(array $fields, array $allowedHandles): array {
        return array_values(array_diff(array_map('strval', array_keys($fields)), $allowedHandles));
    }

    /**
     * Assert every field handle in the payload exists on the block type.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string> $allowedHandles
     * @throws ToolCallException Listing unknown and available handles
     */
    public static function assertKnownHandles(array $fields, array $allowedHandles, string $blockTypeHandle): void {
        $unknown = self::unknownHandles($fields, $allowedHandles);

        if ($unknown === []) {
            return;
        }

        $unknownList = implode(', ', $unknown);
        $availableList = $allowedHandles === [] ? '(none)' : implode(', ', $allowedHandles);

        throw new ToolCallException(
            "Unknown field handle(s) for block type '{$blockTypeHandle}': {$unknownList}. Available fields: {$availableList}",
        );
    }

    /**
     * Summarize an existing Neo block element for diff output.
     *
     * Duck-typed: works with benf\neo\elements\Block without referencing it.
     *
     * @return array<string, mixed>
     */
    public static function summarizeBlock(object $block): array {
        return [
            'id' => self::prop($block, 'id'),
            'type' => self::typeHandle($block),
            'level' => self::prop($block, 'level'),
            'enabled' => self::prop($block, 'enabled'),
        ];
    }

    /**
     * Build a structured before/after diff for appending one block.
     *
     * @param array<int, array<string, mixed>> $currentBlocks Summaries of existing blocks (see summarizeBlock())
     * @param array<string, mixed> $appendedBlock The block that would be appended
     * @return array<string, mixed>
     */
    public static function diff(array $currentBlocks, array $appendedBlock): array {
        return [
            'before' => [
                'blockCount' => count($currentBlocks),
                'blocks' => $currentBlocks,
            ],
            'after' => [
                'blockCount' => count($currentBlocks) + 1,
                'blocks' => [...$currentBlocks, $appendedBlock],
            ],
            'appended' => $appendedBlock,
        ];
    }

    /**
     * Resolve a block's type handle without referencing Neo classes.
     */
    private static function typeHandle(object $block): ?string {
        if (!method_exists($block, 'getType')) {
            return null;
        }

        try {
            $type = $block->getType();
        } catch (Throwable) {
            return null;
        }

        if (!is_object($type)) {
            return null;
        }

        $handle = self::prop($type, 'handle');

        return is_string($handle) ? $handle : null;
    }

    /**
     * Read a property safely: public properties, then getters, then Yii magic.
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
