<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

/**
 * Matches requested field definitions against existing Craft fields.
 *
 * Pure array logic — no Craft dependencies — used by create_block_type to
 * avoid creating duplicate fields: a requested new field that matches an
 * existing one (same handle, or same type with a similar name) is attached
 * instead of created, and the match is reported.
 *
 * @author 2RM
 */
final class FieldMatcher {
    /**
     * Find an existing field matching a requested new field.
     *
     * Match rules, in order:
     * 1. Exact handle match (any type).
     * 2. Same field type class AND similar name (case-insensitive, ignoring
     *    non-alphanumeric characters: "Sub Heading" matches "subheading").
     *
     * @param string $handle The requested field handle
     * @param string|null $name The requested field name
     * @param string $typeClass The requested field type class (FQCN)
     * @param array<int, array{handle: string, name: string, class: string}> $existing Existing field summaries
     * @return array{handle: string, name: string, class: string, reason: string}|null The matched summary plus a `reason` ('handle' or 'type+name'), or null
     */
    public static function matchExisting(string $handle, ?string $name, string $typeClass, array $existing): ?array {
        foreach ($existing as $candidate) {
            if (($candidate['handle'] ?? null) === $handle) {
                return [...$candidate, 'reason' => 'handle'];
            }
        }

        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        foreach ($existing as $candidate) {
            if (($candidate['class'] ?? null) !== $typeClass) {
                continue;
            }

            if (self::normalizeName($candidate['name'] ?? null) === $normalized) {
                return [...$candidate, 'reason' => 'type+name'];
            }
        }

        return null;
    }

    /**
     * List handles close to a missing handle, best matches first.
     *
     * A candidate is close when one handle contains the other (case-
     * insensitive) or their Levenshtein distance is at most 3.
     *
     * @param array<int, string> $handles
     * @return array<int, string>
     */
    public static function closeCandidates(string $handle, array $handles, int $limit = 5): array {
        $needle = strtolower($handle);
        $scored = [];

        foreach ($handles as $candidate) {
            $score = self::closeness($needle, strtolower($candidate));
            if ($score === null) {
                continue;
            }

            $scored[$candidate] = $score;
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, $limit);
    }

    /**
     * Score a candidate's closeness to the needle (lower is closer), or null
     * when the candidate is not close enough to suggest.
     */
    private static function closeness(string $needle, string $candidate): ?int {
        if ($needle === $candidate) {
            return 0;
        }

        $distance = levenshtein($needle, $candidate);

        if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
            return min($distance, 1);
        }

        return $distance <= 3 ? $distance : null;
    }

    /**
     * Normalize a field name for similarity comparison: lowercase, letters
     * and digits only.
     */
    private static function normalizeName(?string $name): string {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', (string) $name));
    }
}
