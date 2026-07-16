<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use ReflectionObject;
use stdClass;
use Throwable;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\FreeformFormPlan;
use twoRivers\craft\Mcp\support\FreeformLayoutCacheReset;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use yii\base\Event;

/**
 * Freeform form-scaffolding tools for Craft CMS: create_form and update_form.
 *
 * Only registered if the Freeform plugin (solspace/craft-freeform) is
 * installed. create_form creates a minimal single-page form from a simple
 * field spec in one call, mirroring how Freeform's own FormGenerationService
 * persists a form: build a form + layout payload (pages/rows/fields), then
 * trigger the FormsController create + upsert persistence events. update_form
 * edits an existing single-page form's fields the same way, but via the
 * update + upsert events, reusing existing field/row UIDs for kept fields so
 * Freeform updates the same underlying records (and submission-content
 * columns) in place instead of dropping and recreating them. All Freeform
 * access is through lazily-resolved string FQCNs / duck-typed calls so this
 * class loads and its isAvailable() check runs safely when Freeform is absent.
 *
 * v1 scope is intentionally minimal (see docs/wiki/plans/create-form-tool.md):
 * a single page, one field per row (update_form additionally tolerates
 * existing multi-field rows it isn't asked to touch), and the field types
 * text/textarea/email/dropdown/checkbox/number. Notifications, integrations/
 * element-connections, spam protection, multi-page layouts, and conditional
 * rules are NOT configured/edited — forms keep Freeform's defaults (create_
 * form) or their current settings untouched (update_form) for all of them.
 *
 * @author 2RM
 */
class FreeformScaffoldTools implements ConditionalToolProvider {
    /** Freeform "Regular" form type (string FQCN — never imported). */
    private const FORM_TYPE_CLASS = 'Solspace\\Freeform\\Form\\Types\\Regular';

    /** Freeform API FormsController that owns the persistence events. */
    private const FORMS_CONTROLLER_CLASS = 'Solspace\\Freeform\\controllers\\api\\FormsController';

    /** Freeform persist-form event carrying the form + layout payload. */
    private const PERSIST_EVENT_CLASS = 'Solspace\\Freeform\\Events\\Forms\\PersistFormEvent';

    /** Freeform property provider that yields a field type's editable props. */
    private const PROPERTY_PROVIDER_CLASS = 'Solspace\\Freeform\\Bundles\\Attributes\\Property\\PropertyProvider';

    /** Freeform plugin class (for the forms service). */
    private const FREEFORM_CLASS = 'Solspace\\Freeform\\Freeform';

    /**
     * Check if the Freeform plugin is available.
     */
    public static function isAvailable(): bool {
        return FreeformTools::isAvailable();
    }

    /**
     * Create a minimal single-page Freeform form from a field spec.
     */
    #[McpTool(
        name: 'create_form',
        description: 'Create a minimal single-page Freeform form from a simple field spec in one call. name is the display name; handle defaults to StringHelper::toHandle(name) and must be unique. fields is a JSON array of {label, type, handle?, required?, options?} objects, laid out one field per row in the given order. Supported field types (v1): text, textarea, email, dropdown, checkbox, number. dropdown requires options as a non-empty array of {label, value} objects. Field handles default to StringHelper::toHandle(label). The form is persisted through Freeform\'s own create + upsert events (the same path the control panel uses), so it appears in the CP form list. v1 does NOT configure notifications, integrations/element-connections, spam protection, multi-page layouts, or conditional rules — the form takes Freeform\'s defaults for all of them; add those in the CP afterward. The form is created on all sites. Pass dryRun: true to preview the planned form, its fields, and the layout summary (pages/rows/fields) without saving anything.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createForm(
        string $name,
        string $fields,
        ?string $handle = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($name, $fields, $handle, $dryRun): array {
            $this->assertFreeformAvailable();

            if (trim($name) === '') {
                throw new ToolCallException('name must be a non-empty string.');
            }

            $formHandle = FreeformFormPlan::resolveFormHandle($handle, $name);
            $specs = FreeformFormPlan::decodeFields($fields);
            $this->assertHandleAvailable($formHandle);

            $summary = [
                'form' => ['name' => trim($name), 'handle' => $formHandle, 'type' => self::FORM_TYPE_CLASS],
                'fields' => $this->describeFields($specs),
                'layout' => ['pages' => 1, 'rows' => count($specs), 'fields' => count($specs)],
            ];

            if ($dryRun) {
                return Response::success(['dryRun' => true, ...$summary]);
            }

            $form = $this->runAsAdmin(fn (): object => $this->persistForm($name, $formHandle, $specs));
            $this->flushFormCache();
            $summary['form'] = ['id' => $this->readFormId($form), ...$summary['form']];

            return Response::success($summary);
        });
    }

    /**
     * Add, remove, or reorder fields on an existing single-page Freeform form.
     */
    #[McpTool(
        name: 'update_form',
        description: 'Add, remove, or reorder fields on an existing single-page Freeform form. Identify the form by handle or id (one is required). fields is the COMPLETE desired field list for the form: a JSON array of {label, type, handle?, required?, options?} objects (same shape and v1 type subset as create_form: text, textarea, email, dropdown, checkbox, number), in the desired final order. Existing fields are matched by handle: a spec whose handle matches a current field KEEPS that field\'s identity, so its stored submission data survives (the underlying record and submission-content column are updated in place, not dropped and recreated) — its label/type/required/options/position update to match the spec. A spec with a handle not currently on the form ADDS a new field. An existing v1-supported field whose handle is absent from fields is REMOVED, and its stored submission values are dropped with it. Fields whose current type is outside the v1 subset (e.g. file upload, signature, group, table) are always left untouched and can never be removed via this tool, even when omitted from fields — edit those in the Freeform control panel. Rejects forms with more than one page, and rejects an edit where a kept/added field would end up sharing a row with an untouched complex-type field. Pass dryRun: true to preview the old -> new field diff (kept/added/removed, plus which existing fields are left untouched) without saving.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateForm(
        string $fields,
        ?string $handle = null,
        ?int $id = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($fields, $handle, $id, $dryRun): array {
            $this->assertFreeformAvailable();

            $form = $this->resolveForm($handle, $id);
            $formId = $this->requireFormId($form);
            $specs = FreeformFormPlan::decodeFields($fields);

            $rawLayout = $this->loadRawLayout($formId);
            $page = $this->assertSinglePage($rawLayout['pages']);
            $existingFields = $this->normalizeExistingFields($rawLayout['fields'], $rawLayout['rows']);

            $plan = FreeformFormPlan::planFieldChanges($existingFields, $specs);
            $this->assertNoRowConflicts($plan['conflicts']);

            $diff = $this->describeDiff($existingFields, $plan);

            if ($dryRun) {
                return Response::success(['dryRun' => true, 'form' => ['id' => $formId], 'diff' => $diff]);
            }

            $layoutUid = $this->resolveLayoutUid($rawLayout['layouts'], (int) $page['layoutId']);
            $payload = $this->buildUpdatePayload($formId, $page, $layoutUid, $plan);

            $updated = $this->runAsAdmin(fn (): object => $this->persistFormUpdate($payload, $formId));
            $this->flushFormCache();

            return Response::success(['form' => ['id' => $this->readFormId($updated)], 'diff' => $diff]);
        });
    }

    /**
     * Resolve the target form for update_form by handle or id.
     *
     * @throws ToolCallException
     */
    private function resolveForm(?string $handle, ?int $id): object {
        if ($handle === null && $id === null) {
            throw new ToolCallException('Either handle or id must be provided.');
        }

        $forms = $this->freeformForms();
        $form = $id !== null ? $forms->getFormById($id) : $forms->getFormByHandle($handle);

        if (is_object($form)) {
            return $form;
        }

        $identifier = $id !== null ? "id {$id}" : "handle '{$handle}'";

        throw new ToolCallException("Form with {$identifier} not found.");
    }

    /**
     * @throws ToolCallException
     */
    private function requireFormId(object $form): int {
        $id = $this->readFormId($form);
        if (is_numeric($id)) {
            return (int) $id;
        }

        throw new ToolCallException('Could not resolve the numeric id of the form to update.');
    }

    /**
     * Read the form's current pages/layouts/rows/fields straight from
     * Freeform's tables. Freeform's Field/Page model getters (getUid(),
     * getRowUid(), etc.) cover identity, but a kept field's exact stored
     * properties (options, custom label copy, etc.) and a page's exact
     * button/metadata configuration are only available as raw metadata JSON
     * — reading it directly lets update_form pass unsupported-type fields
     * and untouched page settings through byte-for-byte instead of
     * reconstructing them (and risking drift) from model getters.
     *
     * @return array{pages: array<int, array<string, mixed>>, layouts: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}
     */
    private function loadRawLayout(int $formId): array {
        return [
            'pages' => (new Query())
                ->select(['id', 'layoutId', 'label', 'order', 'metadata', 'uid'])
                ->from('{{%freeform_forms_pages}}')
                ->where(['formId' => $formId])
                ->orderBy(['order' => \SORT_ASC])
                ->all(),
            'layouts' => (new Query())
                ->select(['id', 'uid'])
                ->from('{{%freeform_forms_layouts}}')
                ->where(['formId' => $formId])
                ->all(),
            'rows' => (new Query())
                ->select(['id', 'order', 'uid'])
                ->from('{{%freeform_forms_rows}}')
                ->where(['formId' => $formId])
                ->indexBy('id')
                ->all(),
            'fields' => (new Query())
                ->select(['id', 'type', 'metadata', 'rowId', 'order', 'uid'])
                ->from('{{%freeform_forms_fields}}')
                ->where(['formId' => $formId])
                ->all(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    private function assertSinglePage(array $pages): array {
        if (count($pages) === 1) {
            return $pages[0];
        }

        throw new ToolCallException(sprintf(
            'update_form only supports single-page forms; this form has %d pages. Edit multi-page forms in the Freeform control panel.',
            count($pages),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{handle:string, uid:string, rowUid:string, rowOrder:int, fieldOrder:int, typeClass:string, metadata:array<string, mixed>, supported:bool}>
     */
    private function normalizeExistingFields(array $fields, array $rows): array {
        return array_map(
            static function (array $field) use ($rows): array {
                $metadata = json_decode((string) $field['metadata'], true);
                $metadata = is_array($metadata) ? $metadata : [];
                $row = $rows[$field['rowId']] ?? ['order' => 0, 'uid' => ''];
                $typeClass = (string) $field['type'];

                return [
                    'handle' => (string) ($metadata['handle'] ?? ''),
                    'uid' => (string) $field['uid'],
                    'rowUid' => (string) $row['uid'],
                    'rowOrder' => (int) $row['order'],
                    'fieldOrder' => (int) $field['order'],
                    'typeClass' => $typeClass,
                    'metadata' => $metadata,
                    'supported' => FreeformFormPlan::resolveExistingType($typeClass) !== null,
                ];
            },
            $fields,
        );
    }

    /**
     * @throws ToolCallException
     */
    private function assertNoRowConflicts(array $conflicts): void {
        if ($conflicts === []) {
            return;
        }

        throw new ToolCallException(
            'This form has a row combining a field you are editing with a field of an unsupported '
            . 'type that update_form always leaves untouched, so it cannot safely plan this edit. '
            . 'Edit this form in the Freeform control panel instead. Affected row(s): '
            . implode(', ', $conflicts) . '.',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $layouts
     * @throws ToolCallException
     */
    private function resolveLayoutUid(array $layouts, int $layoutId): string {
        $uid = array_column($layouts, 'uid', 'id')[$layoutId] ?? null;

        if (is_string($uid)) {
            return $uid;
        }

        throw new ToolCallException("Could not resolve the form's layout.");
    }

    /**
     * Build the update payload: existing page/layout reused verbatim, fields
     * and rows built from the plan (managed fields get real properties from
     * their spec; preserved fields pass their stored metadata through
     * unchanged; new fields/rows get fresh UUIDs assigned here since
     * FreeformFormPlan stays Craft-boot-free).
     *
     * @param array<string, mixed> $page
     */
    private function buildUpdatePayload(int $formId, array $page, string $layoutUid, array $plan): stdClass {
        $propertyProvider = $this->propertyProvider();
        $rowUidByKey = $this->resolveManagedRowUids($plan['managed']);

        $managedFields = array_map(
            fn (array $managed): stdClass => (object) [
                'uid' => $managed['isNew'] ? StringHelper::UUID() : (string) $managed['existingUid'],
                'rowUid' => $rowUidByKey[$managed['rowKey']],
                'typeClass' => $managed['spec']['typeClass'],
                'order' => $managed['fieldOrder'],
                'properties' => (object) $this->buildFieldProperties($propertyProvider, $managed['spec']),
            ],
            $plan['managed'],
        );

        $preservedFields = array_map(
            static fn (array $preserved): stdClass => (object) [
                'uid' => $preserved['uid'],
                'rowUid' => $preserved['rowUid'],
                'typeClass' => $preserved['typeClass'],
                'order' => $preserved['fieldOrder'],
                'properties' => (object) $preserved['metadata'],
            ],
            $plan['preserved'],
        );

        $managedRowOrderByKey = array_column($plan['managed'], 'rowOrder', 'rowKey');
        $managedRows = array_map(
            static fn (string $rowKey, int $order): stdClass => (object) [
                'uid' => $rowUidByKey[$rowKey],
                'layoutUid' => $layoutUid,
                'order' => $order,
            ],
            array_keys($managedRowOrderByKey),
            $managedRowOrderByKey,
        );

        $preservedRowOrderByUid = array_column($plan['preserved'], 'rowOrder', 'rowUid');
        $preservedRows = array_map(
            static fn (string $rowUid, int $order): stdClass => (object) [
                'uid' => $rowUid,
                'layoutUid' => $layoutUid,
                'order' => $order,
            ],
            array_keys($preservedRowOrderByUid),
            $preservedRowOrderByUid,
        );

        $pageMetadata = json_decode((string) $page['metadata'], true);
        $buttons = is_array($pageMetadata) ? ($pageMetadata['buttons'] ?? []) : [];

        return (object) [
            'form' => $this->buildUpdateFormObject($formId),
            'layout' => (object) [
                'pages' => [(object) [
                    'uid' => $page['uid'],
                    'layoutUid' => $layoutUid,
                    'order' => 0,
                    'label' => $page['label'],
                    'buttons' => $buttons,
                ]],
                'layouts' => [(object) ['uid' => $layoutUid]],
                'rows' => [...$managedRows, ...$preservedRows],
                'fields' => [...$managedFields, ...$preservedFields],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $managed
     * @return array<string, string>
     */
    private function resolveManagedRowUids(array $managed): array {
        $map = [];

        foreach ($managed as $item) {
            $rowKey = $item['rowKey'];
            if (array_key_exists($rowKey, $map)) {
                continue;
            }

            $map[$rowKey] = $item['isNew'] ? StringHelper::UUID() : (string) $item['existingRowUid'];
        }

        return $map;
    }

    /**
     * Reload the form's uid + current settings metadata and pass it through
     * unchanged — update_form v1 never edits form-level settings (name,
     * handle, behavior, integrations, etc.), only its fields/layout, so the
     * payload must carry every current settings namespace/property verbatim
     * or Freeform's own persistence would reset any namespace this payload
     * omits back to that property's type default (see
     * FormPersistence::getValidatedMetadata()).
     *
     * @throws ToolCallException
     */
    private function buildUpdateFormObject(int $formId): stdClass {
        $row = (new Query())
            ->select(['uid', 'metadata'])
            ->from('{{%freeform_forms}}')
            ->where(['id' => $formId])
            ->one();

        if ($row === null) {
            throw new ToolCallException("Could not reload form {$formId} for update.");
        }

        $settings = json_decode((string) $row['metadata']);

        return (object) [
            'uid' => $row['uid'],
            'type' => self::FORM_TYPE_CLASS,
            'settings' => is_object($settings) ? $settings : (object) [],
        ];
    }

    /**
     * Build the old -> new field diff returned for dryRun previews and (for
     * confirmation) alongside the saved result.
     *
     * @param array<int, array<string, mixed>> $existingFields
     * @return array<string, mixed>
     */
    private function describeDiff(array $existingFields, array $plan): array {
        $existingByHandle = array_column($existingFields, null, 'handle');

        $kept = array_values(array_filter($plan['managed'], static fn (array $m): bool => !$m['isNew']));
        $added = array_values(array_filter($plan['managed'], static fn (array $m): bool => $m['isNew']));

        return [
            'kept' => array_map(
                static fn (array $m): array => [
                    'handle' => $m['spec']['handle'],
                    'before' => self::existingFieldSummary($existingByHandle[$m['spec']['handle']] ?? []),
                    'after' => self::specFieldSummary($m['spec']),
                ],
                $kept,
            ),
            'added' => array_map(
                static fn (array $m): array => self::specFieldSummary($m['spec']),
                $added,
            ),
            'removed' => array_map(
                static fn (array $r): array => self::existingFieldSummary($existingByHandle[$r['handle']] ?? []),
                $plan['removed'],
            ),
            'untouched' => array_map(
                static fn (array $p): array => ['handle' => $p['handle']],
                $plan['preserved'],
            ),
            'order' => array_map(static fn (array $m): string => $m['spec']['handle'], $plan['managed']),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function specFieldSummary(array $spec): array {
        return [
            'label' => $spec['label'],
            'type' => $spec['type'],
            'required' => $spec['required'],
            'options' => $spec['options'],
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private static function existingFieldSummary(array $existing): array {
        if ($existing === []) {
            return [];
        }

        $metadata = $existing['metadata'] ?? [];

        return [
            'label' => $metadata['label'] ?? null,
            'type' => FreeformFormPlan::resolveExistingType($existing['typeClass'] ?? '') ?? ($existing['typeClass'] ?? null),
            'required' => (bool) ($metadata['required'] ?? false),
        ];
    }

    /**
     * Build the payload and persist a new form through Freeform's create +
     * upsert events, mirroring FormGenerationService.
     *
     * @param array<int, array<string, mixed>> $specs
     * @throws ToolCallException
     */
    private function persistForm(string $name, string $handle, array $specs): object {
        $payload = $this->buildPayload($name, $handle, $specs);

        return $this->triggerPersist($payload, null, 'EVENT_CREATE_FORM');
    }

    /**
     * Persist an existing form's edited layout through Freeform's update +
     * upsert events.
     *
     * @throws ToolCallException
     */
    private function persistFormUpdate(stdClass $payload, int $formId): object {
        return $this->triggerPersist($payload, $formId, 'EVENT_UPDATE_FORM');
    }

    /**
     * Trigger the given primary persistence event followed by the shared
     * upsert event (which both LayoutPersistence and the content-table
     * column sync bundle listen on), then return the resulting form.
     *
     * @throws ToolCallException
     */
    private function triggerPersist(stdClass $payload, ?int $formId, string $primaryEventConst): object {
        $event = $this->makePersistEvent($payload, $formId);
        $controller = self::FORMS_CONTROLLER_CLASS;

        // Bust LayoutPersistence's stale per-request row-id memo before saving,
        // or a newly-added field's row would resolve to a null rowId in the
        // long-running server and be orphaned (issue #28). See the helper's
        // docblock. Shared by create_form and update_form via this method.
        FreeformLayoutCacheReset::reset($controller, (string) constant($controller . '::EVENT_UPSERT_FORM'));

        Event::trigger($controller, (string) constant($controller . '::' . $primaryEventConst), $event);
        Event::trigger($controller, (string) constant($controller . '::EVENT_UPSERT_FORM'), $event);

        $this->assertNoPersistErrors($event);

        $form = $event->getForm();
        if (!is_object($form)) {
            throw new ToolCallException('Freeform did not return a form after the operation.');
        }

        return $form;
    }

    /**
     * Instantiate the Freeform PersistFormEvent. Returns mixed so callers
     * duck-type its methods (the class is unknown without Freeform).
     */
    private function makePersistEvent(stdClass $payload, ?int $formId = null): mixed {
        $class = self::PERSIST_EVENT_CLASS;

        return new $class($payload, $formId);
    }

    /**
     * @throws ToolCallException
     */
    private function assertNoPersistErrors(mixed $event): void {
        if (!is_object($event) || !method_exists($event, 'hasErrors') || !$event->hasErrors()) {
            return;
        }

        $data = method_exists($event, 'getResponseData') ? $event->getResponseData() : [];
        $errors = is_array($data) ? ($data['errors'] ?? $data) : $data;

        throw new ToolCallException('Freeform rejected the form: ' . json_encode($errors));
    }

    /**
     * Build the form + layout payload for a single-page form: one page, one
     * layout, one row per field, fields in the given order.
     *
     * @param array<int, array<string, mixed>> $specs
     */
    private function buildPayload(string $name, string $handle, array $specs): stdClass {
        $layoutUid = StringHelper::UUID();
        $pageUid = StringHelper::UUID();
        $rowUids = array_map(static fn (): string => StringHelper::UUID(), $specs);
        $propertyProvider = $this->propertyProvider();

        $rows = array_map(
            static fn (string $rowUid, int $order): stdClass => (object) [
                'uid' => $rowUid,
                'layoutUid' => $layoutUid,
                'order' => $order,
            ],
            $rowUids,
            array_keys($rowUids),
        );

        $fields = array_map(
            fn (array $spec, int $order): stdClass => (object) [
                'uid' => StringHelper::UUID(),
                'rowUid' => $rowUids[$order],
                'typeClass' => $spec['typeClass'],
                'order' => $order,
                'properties' => (object) $this->buildFieldProperties($propertyProvider, $spec),
            ],
            $specs,
            array_keys($specs),
        );

        return (object) [
            'form' => $this->buildFormObject($name, $handle),
            'layout' => (object) [
                'pages' => [(object) [
                    'uid' => $pageUid,
                    'layoutUid' => $layoutUid,
                    'order' => 0,
                    'label' => 'Page 1',
                    'buttons' => $this->pageButtons(),
                ]],
                'layouts' => [(object) ['uid' => $layoutUid]],
                'rows' => $rows,
                'fields' => $fields,
            ],
        ];
    }

    private function buildFormObject(string $name, string $handle): stdClass {
        return (object) [
            'uid' => StringHelper::UUID(),
            'type' => self::FORM_TYPE_CLASS,
            'settings' => (object) [
                'general' => (object) [
                    'name' => trim($name),
                    'handle' => $handle,
                    'type' => self::FORM_TYPE_CLASS,
                    'formattingTemplate' => '',
                    'storeData' => true,
                    'sites' => Craft::$app->getSites()->getAllSiteIds(),
                    'description' => '',
                ],
            ],
        ];
    }

    private function pageButtons(): stdClass {
        return (object) [
            'layout' => 'submit',
            'submitLabel' => 'Submit',
            'back' => false,
            'backLabel' => 'Back',
            'save' => false,
            'saveLabel' => 'Save',
            'saveRedirectUrl' => '',
        ];
    }

    /**
     * Merge a field type's editable-property defaults with the spec's
     * label/handle/required (and dropdown option configuration), so every
     * property Freeform validates on save is present.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function buildFieldProperties(mixed $propertyProvider, array $spec): array {
        $properties = [];
        foreach ($propertyProvider->getEditableProperties($spec['typeClass']) as $property) {
            $properties[$property->handle] = $property->value;
        }

        $properties['label'] = $spec['label'];
        $properties['handle'] = $spec['handle'];
        $properties['required'] = $spec['required'];

        return $this->applyChoiceOptions($properties, $spec);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function applyChoiceOptions(array $properties, array $spec): array {
        if (!in_array($spec['type'], FreeformFormPlan::OPTION_FIELD_TYPES, true)) {
            return $properties;
        }

        $properties['optionConfiguration'] = FreeformFormPlan::optionConfiguration($spec['options']);

        return $properties;
    }

    /**
     * Resolve Freeform's PropertyProvider from the Craft container. Returns
     * mixed so its methods are duck-typed (unknown class without Freeform).
     * The container throws if it cannot resolve the class, so the result is
     * always a usable object here.
     */
    private function propertyProvider(): mixed {
        return Craft::$container->get(self::PROPERTY_PROVIDER_CLASS);
    }

    /**
     * Freeform's form persistence reads the author from the current user
     * identity and dereferences it, so a form cannot be created without one.
     * Set an admin identity for the operation when none is present (e.g. the
     * long-running MCP server process), restoring it afterwards.
     *
     * @param callable(): object $fn
     * @throws ToolCallException
     */
    private function runAsAdmin(callable $fn): object {
        $session = Craft::$app->getUser();
        if ($session->getIdentity() !== null) {
            return $fn();
        }

        $admin = User::find()->admin(true)->status(null)->one();
        if (!$admin instanceof User) {
            throw new ToolCallException(
                'Freeform form creation requires an authenticated user as the form author, but no admin user exists to act as one.',
            );
        }

        $session->setIdentity($admin);

        try {
            return $fn();
        } finally {
            $session->setIdentity(null);
        }
    }

    private function readFormId(object $form): mixed {
        if (method_exists($form, 'getId')) {
            return $form->getId();
        }

        return $form->id ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $specs
     * @return array<int, array<string, mixed>>
     */
    private function describeFields(array $specs): array {
        return array_map(
            static fn (array $spec): array => [
                'label' => $spec['label'],
                'handle' => $spec['handle'],
                'type' => $spec['type'],
                'typeClass' => $spec['typeClass'],
                'required' => $spec['required'],
                'options' => $spec['options'],
            ],
            $specs,
        );
    }

    /**
     * @throws ToolCallException
     */
    private function assertHandleAvailable(string $handle): void {
        $existing = $this->freeformForms()->getFormByHandle($handle);
        if ($existing === null) {
            return;
        }

        throw new ToolCallException("A Freeform form with handle '{$handle}' already exists.");
    }

    /**
     * Resolve Freeform's forms service. Returns mixed for duck-typed access.
     */
    private function freeformForms(): mixed {
        $freeform = self::FREEFORM_CLASS;

        return $freeform::getInstance()->forms;
    }

    /**
     * Bust Freeform's process-static form cache after a create.
     *
     * Freeform's FormsService memoizes forms in a Solspace\Freeform\Library\
     * Cache\Memo instance (its private $cache) meant to live for a single web
     * request. In the long-running MCP server that forms-service singleton —
     * and its Memo — persist across tool calls, and the create path never
     * clears them. Two stale reads result until a server restart:
     * assertHandleAvailable()'s pre-save getFormByHandle() of the new handle
     * caches a null under that handle (so a follow-up get_form by handle
     * returns "not found"), and any earlier list_forms freezes the all-forms
     * list (so list_forms omits the new form). Clearing the Memo makes the
     * new form visible to follow-up calls immediately.
     *
     * ponytail: reaches into a Freeform-internal service via reflection —
     * FormsService exposes no public cache-clear method — fully guarded, so
     * if Freeform renames the property or drops clear() the cache simply
     * isn't busted (no fatal). Echo-only: never fails the persisted create.
     */
    private function flushFormCache(): void {
        try {
            $forms = $this->freeformForms();
        } catch (Throwable) {
            return;
        }

        if (!is_object($forms)) {
            return;
        }

        $reflection = new ReflectionObject($forms);
        if (!$reflection->hasProperty('cache')) {
            return;
        }

        $cache = $reflection->getProperty('cache')->getValue($forms);
        if (!is_object($cache) || !method_exists($cache, 'clear')) {
            return;
        }

        $cache->clear();
    }

    /**
     * @throws ToolCallException
     */
    private function assertFreeformAvailable(): void {
        if (self::isAvailable()) {
            return;
        }

        throw new ToolCallException('Freeform is not installed or not enabled');
    }
}
