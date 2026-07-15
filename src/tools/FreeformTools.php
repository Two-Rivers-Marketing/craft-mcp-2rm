<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\helpers\FileHelper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use Solspace\Freeform\Elements\Submission;
use Solspace\Freeform\Freeform;
use Throwable;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\FreeformSerializer;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Freeform tools for Craft CMS.
 *
 * Only registered if the Freeform plugin (solspace/craft-freeform) is
 * installed. All Freeform access is duck-typed (class_exists guards, string
 * FQCNs resolved lazily by PHP) so this class loads and its isAvailable()
 * check runs safely even when Freeform is absent — nothing here fatals in
 * an environment without the plugin.
 *
 * A form's field layout, notifications, and element connections (the
 * feature that maps submissions to Craft entry creation) are not part of
 * Freeform's stable public PHP API and have shifted shape across major
 * versions; get_form() serializes them defensively and returns null/empty
 * where a given accessor is absent rather than guessing at internals.
 *
 * @author 2RM
 */
class FreeformTools implements ConditionalToolProvider {
    /** Inline CSV content in export_submissions responses up to this many bytes. */
    private const INLINE_CONTENT_LIMIT = 32768;

    /**
     * Check if the Freeform plugin is available.
     *
     * Uses cached plugin state first (fast), falls back to project config
     * to detect plugins installed after MCP server start.
     */
    public static function isAvailable(): bool {
        if (!class_exists(Freeform::class)) {
            return false;
        }

        $plugins = Craft::$app->getPlugins();

        if ($plugins->isPluginEnabled('freeform')) {
            return true;
        }

        $config = Craft::$app->getProjectConfig()->get('plugins.freeform');
        $enabledInConfig = $config !== null && ($config['enabled'] ?? false) === true;

        if (!$enabledInConfig) {
            return false;
        }

        $plugins->loadPlugins();

        return $plugins->isPluginEnabled('freeform');
    }

    /**
     * List all Freeform forms.
     */
    #[McpTool(
        name: 'list_forms',
        description: 'List all Freeform forms with id, handle, name, and submission count.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listForms(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $this->assertFreeformAvailable();

            $forms = Freeform::getInstance()->forms->getAllForms();
            $result = array_map($this->serializeFormSummary(...), $forms);

            return Response::list('forms', $result);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFormSummary(object $form): array {
        $summary = FreeformSerializer::formSummary($form);
        $summary['submissionCount'] = $this->submissionCount($summary['id']);

        return $summary;
    }

    private function submissionCount(mixed $formId): ?int {
        if (!is_numeric($formId) || !class_exists(Submission::class)) {
            return null;
        }

        try {
            // count() returns a string from the DB layer; cast to int or the
            // ?int return type throws a TypeError under strict_types (silently
            // swallowed by the catch, surfacing as a null count).
            return (int) Submission::find()->formId((int) $formId)->status(null)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get a single form's layout, notifications, connections, and spam settings.
     */
    #[McpTool(
        name: 'get_form',
        description: 'Get a single Freeform form by handle or id: its field layout (handle/type/label/required per field), notification settings (form-specific email notification templates), element connections (Freeform "elements" integrations that map a submission to a created Craft entry/element), and spam-protection settings (captcha + spam-blocking integrations). Powers debugging "why didn\'t this submission create an entry" — an empty connections list means the form creates no element. Each section is a list; empty means nothing of that kind is configured on the form.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getForm(?string $handle = null, ?int $id = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($handle, $id): array {
            $this->assertFreeformAvailable();

            $form = $this->resolveForm($handle, $id);

            return Response::found('form', FreeformSerializer::form(
                $form,
                $this->formNotifications($form),
                $this->integrationsForForm($form, ['elements']),
                $this->integrationsForForm($form, ['captchas', 'spam-blocking']),
            ));
        });
    }

    /**
     * Freeform notification templates bound to this form (formId matches).
     * getAllFormNotifications() returns every form-scoped template across all
     * forms, so filter to this one. Degrades to [] rather than throwing.
     *
     * @return array<int, object>
     */
    private function formNotifications(object $form): array {
        $formId = FreeformSerializer::readProp($form, 'id');
        if (!is_numeric($formId)) {
            return [];
        }

        try {
            $all = Freeform::getInstance()->notifications->getAllFormNotifications();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $all,
            static fn (object $n): bool => (int) FreeformSerializer::readProp($n, 'formId') === (int) $formId,
        ));
    }

    /**
     * Form integrations of the given type categories (e.g. 'elements',
     * 'captchas', 'spam-blocking'). Each type is read independently and
     * degrades to [] on failure, keeping partial results.
     *
     * @param array<int, string> $types
     * @return array<int, object>
     */
    private function integrationsForForm(object $form, array $types): array {
        $service = Freeform::getInstance()->integrations;

        return array_merge([], ...array_map(
            fn (string $type): array => $this->integrationsOfType($service, $form, $type),
            $types,
        ));
    }

    /**
     * @return array<int, object>
     */
    private function integrationsOfType(object $service, object $form, string $type): array {
        if (!method_exists($service, 'getForForm')) {
            return [];
        }

        try {
            $result = $service->getForForm($form, $type);
        } catch (Throwable) {
            return [];
        }

        return is_iterable($result) ? array_values(array_filter([...$result], 'is_object')) : [];
    }

    /**
     * List submissions, optionally scoped to a form and/or status.
     */
    #[McpTool(
        name: 'list_submissions',
        description: 'List Freeform submissions with id, form, title/date, and status. Filter by formHandle and/or status. limit/offset paginate.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listSubmissions(
        ?string $formHandle = null,
        int $limit = 20,
        int $offset = 0,
        ?string $status = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($formHandle, $limit, $offset, $status): array {
            $this->assertFreeformAvailable();

            $query = Submission::find();
            $this->scopeToForm($query, $formHandle);
            $this->applyStatusFilter($query, $status);
            $query->limit($limit)->offset($offset);

            $submissions = $query->all();
            $result = array_map(FreeformSerializer::submissionSummary(...), $submissions);

            return Response::list('submissions', $result, [
                'formHandle' => $formHandle,
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        });
    }

    /**
     * Get a submission's full field values.
     */
    #[McpTool(
        name: 'get_submission',
        description: 'Get a single Freeform submission by id, including every field value keyed by field handle.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getSubmission(int $id, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id): array {
            $this->assertFreeformAvailable();

            $submission = $this->resolveSubmission($id);
            $handles = $this->fieldHandlesOf($this->formOf($submission));

            return Response::found('submission', [
                ...FreeformSerializer::submissionSummary($submission),
                'fields' => FreeformSerializer::submissionFieldValues($submission, $handles),
            ]);
        });
    }

    /**
     * Delete a submission.
     */
    #[McpTool(
        name: 'delete_submission',
        description: 'Delete a single Freeform submission by id. Pass dryRun: true to preview what would be deleted without deleting.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function deleteSubmission(int $id, bool $dryRun = false, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $dryRun): array {
            $this->assertFreeformAvailable();

            $submission = $this->resolveSubmission($id);
            $summary = FreeformSerializer::submissionSummary($submission);

            if ($dryRun) {
                return Response::success(['dryRun' => true, 'submission' => $summary]);
            }

            if (!Craft::$app->getElements()->deleteElement($submission)) {
                throw new ToolCallException("Failed to delete submission {$id}.");
            }

            return Response::success(['deleted' => true, 'submission' => $summary]);
        });
    }

    /**
     * Export a form's submissions to CSV.
     */
    #[McpTool(
        name: 'export_submissions',
        description: 'Export a Freeform form\'s submissions to CSV: one column per form field plus id/dateCreated/status. Filter with limit and/or since (a date string, submissions on/after it). Writes the CSV to a server-local temp file and returns its path; when the CSV is small (<=32KB) the content is also returned inline. Only the csv format is currently supported.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function exportSubmissions(
        string $formHandle,
        string $format = 'csv',
        ?int $limit = null,
        ?string $since = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($formHandle, $format, $limit, $since): array {
            $this->assertFreeformAvailable();
            $this->assertCsvFormat($format);

            $form = $this->resolveForm($formHandle, null);
            $handles = $this->fieldHandlesOf($form);

            $query = Submission::find();
            $this->scopeToForm($query, $formHandle);
            $this->applySinceFilter($query, $since);
            $this->applyLimit($query, $limit);

            $rows = array_map(
                fn (object $submission): array => $this->exportRow($submission, $handles),
                $query->all(),
            );
            $headers = ['id', 'dateCreated', 'status', ...$handles];
            $csv = FreeformSerializer::toCsv($rows, $headers);
            $path = $this->writeExportFile($formHandle, $csv);

            $response = [
                'format' => 'csv',
                'formHandle' => $formHandle,
                'rowCount' => count($rows),
                'path' => $path,
            ];

            if (strlen($csv) <= self::INLINE_CONTENT_LIMIT) {
                $response['content'] = $csv;
            }

            return Response::success($response);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRow(object $submission, array $handles): array {
        $summary = FreeformSerializer::submissionSummary($submission);
        $row = [
            'id' => $summary['id'],
            'dateCreated' => $summary['dateCreated'],
            'status' => $summary['status'],
        ];

        foreach (FreeformSerializer::submissionFieldValues($submission, $handles) as $handle => $value) {
            $row[$handle] = is_scalar($value) || $value === null ? $value : json_encode($value);
        }

        return $row;
    }

    private function writeExportFile(string $formHandle, string $csv): string {
        $dir = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'freeform-exports';
        FileHelper::createDirectory($dir);

        $safeHandle = preg_replace('/[^A-Za-z0-9_-]/', '_', $formHandle) ?? 'form';
        $path = $dir . DIRECTORY_SEPARATOR . "freeform-{$safeHandle}-" . date('Ymd-His') . '.csv';

        if (file_put_contents($path, $csv) === false) {
            throw new ToolCallException("Failed to write export file to {$path}.");
        }

        return $path;
    }

    private function assertCsvFormat(string $format): void {
        if (strtolower($format) === 'csv') {
            return;
        }

        throw new ToolCallException("Unsupported export format '{$format}'. Only 'csv' is currently supported.");
    }

    /**
     * Resolve a form by handle or id.
     *
     * @throws ToolCallException
     */
    private function resolveForm(?string $handle, ?int $id): object {
        if ($handle === null && $id === null) {
            throw new ToolCallException('Either handle or id must be provided');
        }

        $forms = Freeform::getInstance()->forms;
        $form = $id !== null ? $forms->getFormById($id) : $forms->getFormByHandle($handle);

        if ($form === null) {
            $identifier = $id !== null ? "id {$id}" : "handle '{$handle}'";

            throw new ToolCallException("Form with {$identifier} not found");
        }

        return $form;
    }

    /**
     * @throws ToolCallException
     */
    private function resolveSubmission(int $id): object {
        $submission = Freeform::getInstance()->submissions->getSubmissionById($id);

        if ($submission === null) {
            throw new ToolCallException("Submission with ID {$id} not found");
        }

        return $submission;
    }

    /**
     * Resolve the form a submission belongs to, if it can be determined.
     */
    private function formOf(object $submission): ?object {
        if (method_exists($submission, 'getForm')) {
            try {
                $form = $submission->getForm();
                if (is_object($form)) {
                    return $form;
                }
            } catch (Throwable) {
                return null;
            }
        }

        $formId = $submission->formId ?? null;
        if (!is_numeric($formId)) {
            return null;
        }

        return Freeform::getInstance()->forms->getFormById((int) $formId);
    }

    /**
     * @return array<int, string>
     */
    private function fieldHandlesOf(?object $form): array {
        if ($form === null) {
            return [];
        }

        $handles = array_column(FreeformSerializer::fieldLayout($form), 'handle');

        return array_values(array_filter(
            $handles,
            static fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        ));
    }

    /**
     * Scope a submission query to a form by handle, when Freeform's
     * SubmissionQuery exposes a formId() criteria method.
     */
    private function scopeToForm(object $query, ?string $formHandle): void {
        if ($formHandle === null) {
            return;
        }

        $form = $this->resolveForm($formHandle, null);
        $formId = FreeformSerializer::readProp($form, 'id');

        if (!is_numeric($formId) || !method_exists($query, 'formId')) {
            return;
        }

        $query->formId((int) $formId);
    }

    /**
     * Apply a status filter if the query supports it. Some Freeform versions
     * may not expose arbitrary status criteria; unsupported filters are
     * silently skipped rather than failing the whole query.
     */
    private function applyStatusFilter(object $query, ?string $status): void {
        if ($status === null || trim($status) === '' || !method_exists($query, 'status')) {
            return;
        }

        try {
            $query->status($status);
        } catch (Throwable) {
            // Unsupported status criteria on this Freeform version; ignore.
        }
    }

    private function applySinceFilter(object $query, ?string $since): void {
        if ($since === null || trim($since) === '' || !method_exists($query, 'dateCreated')) {
            return;
        }

        $query->dateCreated(['>=', $since]);
    }

    private function applyLimit(object $query, ?int $limit): void {
        if ($limit === null || !method_exists($query, 'limit')) {
            return;
        }

        $query->limit($limit);
    }

    /**
     * Assert Freeform is available, throw exception if not.
     *
     * @throws ToolCallException
     */
    private function assertFreeformAvailable(): void {
        if (!self::isAvailable()) {
            throw new ToolCallException('Freeform is not installed or not enabled');
        }
    }
}
