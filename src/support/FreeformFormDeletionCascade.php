<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use craft\db\Query;
use yii\db\Connection;

/**
 * Computes and executes the full structural orphan cleanup that Freeform's
 * own FormsService::deleteById() leaves behind, plus the would-delete
 * preview summary for delete_form's dryRun (issue #31).
 *
 * WHY THIS EXISTS
 * ---------------
 * Empirically confirmed live (mbd, Freeform 5.15.16):
 * Freeform::getInstance()->forms->deleteById($id) drops the form record
 * (craft_freeform_forms) and the per-form content table
 * (craft_freeform_submissions_<handle>_<id>), and deletes the submission
 * Craft elements — but it LEAVES orphan rows in every one of:
 *
 *   craft_freeform_forms_fields   (keyed by formId)
 *   craft_freeform_forms_rows     (keyed by formId)
 *   craft_freeform_forms_pages    (keyed by formId)
 *   craft_freeform_forms_layouts  (keyed by formId)
 *   craft_freeform_submissions    (submission meta, keyed by formId)
 *   craft_searchindex             (keyed by elementId — the submission ids)
 *   craft_elements_sites          (keyed by elementId — likely)
 *   craft_elements                (keyed by id — the submission ids)
 *
 * Although the install migration defines formId -> freeform_forms.id CASCADE
 * FKs (and freeform_submissions.id -> elements.id CASCADE), the live database
 * does not cascade them on delete, so delete_form must clean the whole set
 * explicitly and then assert 0 remaining rows. searchindex has no FK at all,
 * so it never cascades regardless.
 *
 * FK-SAFE DELETE ORDER
 * --------------------
 * fields -> rows -> pages -> layouts -> submissions-meta -> searchindex ->
 * elements_sites -> elements. Structural children (rows/pages reference the
 * layout, fields reference rows) are removed before their parents; the
 * submission meta row (a child of the element via id FK) is removed before
 * the element rows; searchindex/elements_sites (children of the element) are
 * removed before the element row itself.
 *
 * The row-building/summary logic here is pure (no Craft, no DB) so it is unit
 * testable; only collectSubmissionIds()/countSpecs()/deleteSpecs() touch a
 * live DB connection, which the caller supplies.
 *
 * @author 2RM
 */
final class FreeformFormDeletionCascade {
    /**
     * Freeform structural + submission-meta tables the vendor delete leaves
     * orphaned, each keyed by the form's stable DB id, in FK-safe delete
     * order.
     *
     * @var array<int, string>
     */
    public const FORM_ID_TABLES = [
        '{{%freeform_forms_fields}}',
        '{{%freeform_forms_rows}}',
        '{{%freeform_forms_pages}}',
        '{{%freeform_forms_layouts}}',
        '{{%freeform_submissions}}',
    ];

    /**
     * Craft element-side tables carrying rows for the deleted submissions,
     * keyed by the submission element ids, in FK-safe delete order (index
     * rows and per-site rows before the element rows they hang off).
     *
     * @var array<int, array{table: string, column: string}>
     */
    public const ELEMENT_ID_TABLES = [
        ['table' => '{{%searchindex}}', 'column' => 'elementId'],
        ['table' => '{{%elements_sites}}', 'column' => 'elementId'],
        ['table' => '{{%elements}}', 'column' => 'id'],
    ];

    /**
     * Build the ordered list of cleanup specs for a form. Pure: no DB, no
     * Craft. Element-keyed specs are omitted entirely when the form has no
     * submissions (an empty id set would otherwise produce a no-op IN ()).
     *
     * @param array<int, int> $submissionIds
     * @return array<int, array{label: string, table: string, column: string, values: array<int, int>}>
     */
    public static function buildSpecs(int $formId, array $submissionIds): array {
        $formSpecs = array_map(
            static fn (string $table): array => [
                'label' => self::tableLabel($table),
                'table' => $table,
                'column' => 'formId',
                'values' => [$formId],
            ],
            self::FORM_ID_TABLES,
        );

        if ($submissionIds === []) {
            return $formSpecs;
        }

        $elementSpecs = array_map(
            static fn (array $spec): array => [
                'label' => self::tableLabel($spec['table']),
                'table' => $spec['table'],
                'column' => $spec['column'],
                'values' => array_values($submissionIds),
            ],
            self::ELEMENT_ID_TABLES,
        );

        return [...$formSpecs, ...$elementSpecs];
    }

    /**
     * Strip Craft's `{{%...}}` table wrapper to a bare table name for use as
     * a summary key. Pure.
     */
    public static function tableLabel(string $table): string {
        return trim($table, '{}%');
    }

    /**
     * True when every per-table count in the map is zero. Pure.
     *
     * @param array<string, int> $counts
     */
    public static function allClean(array $counts): bool {
        foreach ($counts as $count) {
            if ($count !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shape delete_form's dryRun preview payload. Pure.
     *
     * @param array<string, int> $wouldDelete
     * @return array<string, mixed>
     */
    public static function dryRunSummary(int $formId, string $handle, string $contentTable, int $submissions, array $wouldDelete): array {
        return [
            'dryRun' => true,
            'form' => ['id' => $formId, 'handle' => $handle],
            'submissions' => $submissions,
            'contentTable' => $contentTable,
            'wouldDelete' => $wouldDelete,
        ];
    }

    /**
     * Shape delete_form's post-delete payload, including the per-table
     * cleaned-row counts and the re-counted orphans-remaining assertion. Pure.
     *
     * @param array<string, int> $cleaned
     * @param array<string, int> $remaining
     * @return array<string, mixed>
     */
    public static function deletedSummary(int $formId, string $handle, string $contentTable, int $submissions, array $cleaned, array $remaining): array {
        return [
            'form' => ['id' => $formId, 'handle' => $handle],
            'submissionsDeleted' => $submissions,
            'contentTableDropped' => $contentTable,
            'cleaned' => $cleaned,
            'orphansRemaining' => $remaining,
            'orphansClean' => self::allClean($remaining),
        ];
    }

    /**
     * Read the submission element ids for a form straight from the
     * freeform_submissions meta table. Deliberately a raw query rather than
     * Freeform's SubmissionQuery, so it does not trip the process-lifetime
     * stale form-map static (see FreeformStaleFormCache) and can never crash
     * on a same-session form.
     *
     * @return array<int, int>
     */
    public static function collectSubmissionIds(Connection $db, int $formId): array {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%freeform_submissions}}')
            ->where(['formId' => $formId])
            ->column($db);

        return array_map(static fn ($id): int => (int) $id, $ids);
    }

    /**
     * Count the current rows each cleanup spec matches. Returns a map of the
     * bare table label to its (int-cast) row count.
     *
     * @param array<int, array{label: string, table: string, column: string, values: array<int, int>}> $specs
     * @return array<string, int>
     */
    public static function countSpecs(Connection $db, array $specs): array {
        $counts = [];

        foreach ($specs as $spec) {
            $counts[$spec['label']] = (int) (new Query())
                ->from($spec['table'])
                ->where([$spec['column'] => $spec['values']])
                ->count('*', $db);
        }

        return $counts;
    }

    /**
     * Delete the rows each cleanup spec matches. Returns a map of the bare
     * table label to the number of rows deleted.
     *
     * @param array<int, array{label: string, table: string, column: string, values: array<int, int>}> $specs
     * @return array<string, int>
     */
    public static function deleteSpecs(Connection $db, array $specs): array {
        $deleted = [];

        foreach ($specs as $spec) {
            $deleted[$spec['label']] = $db
                ->createCommand()
                ->delete($spec['table'], [$spec['column'] => $spec['values']])
                ->execute();
        }

        return $deleted;
    }
}
