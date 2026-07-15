<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
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
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use yii\base\Event;

/**
 * Freeform form-scaffolding tool for Craft CMS.
 *
 * Only registered if the Freeform plugin (solspace/craft-freeform) is
 * installed. Creates a minimal single-page form from a simple field spec in
 * one call, mirroring how Freeform's own FormGenerationService persists a
 * form: build a form + layout payload (pages/rows/fields), then trigger the
 * FormsController create + upsert persistence events. All Freeform access is
 * through lazily-resolved string FQCNs / duck-typed calls so this class loads
 * and its isAvailable() check runs safely when Freeform is absent.
 *
 * v1 scope is intentionally minimal (see docs/wiki/plans/create-form-tool.md):
 * a single page, one field per row, and the field types text/textarea/email/
 * dropdown/checkbox/number. Notifications, integrations/element-connections,
 * spam protection, multi-page layouts, and conditional rules are NOT
 * configured — the form takes Freeform's defaults for all of them.
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
     * Build the payload and persist the form through Freeform's create +
     * upsert events, mirroring FormGenerationService.
     *
     * @param array<int, array<string, mixed>> $specs
     * @throws ToolCallException
     */
    private function persistForm(string $name, string $handle, array $specs): object {
        $payload = $this->buildPayload($name, $handle, $specs);
        $event = $this->makePersistEvent($payload);
        $controller = self::FORMS_CONTROLLER_CLASS;

        Event::trigger($controller, (string) constant($controller . '::EVENT_CREATE_FORM'), $event);
        Event::trigger($controller, (string) constant($controller . '::EVENT_UPSERT_FORM'), $event);

        $this->assertNoPersistErrors($event);

        $form = $event->getForm();
        if (!is_object($form)) {
            throw new ToolCallException('Freeform did not return a form after creation.');
        }

        return $form;
    }

    /**
     * Instantiate the Freeform PersistFormEvent. Returns mixed so callers
     * duck-type its methods (the class is unknown without Freeform).
     */
    private function makePersistEvent(stdClass $payload): mixed {
        $class = self::PERSIST_EVENT_CLASS;

        return new $class($payload);
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
