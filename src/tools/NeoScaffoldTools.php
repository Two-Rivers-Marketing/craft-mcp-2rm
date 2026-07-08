<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use benf\neo\Plugin as Neo;
use Craft;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\Mcp;
use twoRivers\craft\Mcp\support\BlockTypeStub;
use twoRivers\craft\Mcp\support\FieldMatcher;
use twoRivers\craft\Mcp\support\ResolvesNeoBuilderField;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Neo content-builder scaffolding tools for Craft CMS.
 *
 * Only registered if the Neo plugin (benf/craft-neo) is installed. Creates a
 * complete content-builder component in one call: the Neo block type, its
 * attached fields (existing or newly created), and a rendered body_blocks
 * template stub. The block type is saved through Neo's PHP API so Craft
 * writes the project-config YAML itself.
 *
 * @author 2RM
 */
class NeoScaffoldTools implements ConditionalToolProvider {
    use ResolvesNeoBuilderField;

    /** Map of newFields type keywords to Craft field classes. */
    private const FIELD_TYPE_MAP = [
        'plainText' => PlainText::class,
        'dropdown' => Dropdown::class,
        'lightswitch' => Lightswitch::class,
        'asset' => AssetsField::class,
    ];

    /** CKEditor field class (optional plugin), used for the richText type. */
    private const RICH_TEXT_FIELD_CLASS = 'craft\ckeditor\Field';

    /**
     * Check if the Neo plugin is available.
     */
    public static function isAvailable(): bool {
        return NeoSchemaTools::isAvailable();
    }

    /**
     * Create a Neo block type with attached fields and a template stub.
     */
    #[McpTool(
        name: 'create_block_type',
        description: 'Create a new Neo block type (content-builder component) atomically: the block type, its attached fields, and a rendered body_blocks template stub in one call. name is the display name; handle defaults to its camelCase form. Attach existing fields by handle via existingFields (JSON array of strings; an error lists close candidates for unknown handles). Create fields via newFields (JSON array of {name, handle?, type, options?, required?} objects; type is one of plainText, richText, dropdown, lightswitch, asset; dropdown requires options as [{label, value}]). Before creating, each newFields entry is checked against existing fields (same handle, or same type with a similar name) — a match is attached and reported instead of creating a duplicate. childBlockTypes (JSON array of block type handles) marks the block as a container and adds a block.children loop to the stub. The stub is written to templates/body_blocks/<handle>.twig and NEVER overwrites an existing file; pass scaffoldTemplate: false to skip it. The block type is attached to the configured builder Neo field (or fieldHandle) and saved through Neo\'s API so Craft writes the project-config YAML. Pass dryRun: true to preview the block type summary, the field attach/create/match plan, and the stub path + content without saving anything.',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA, dangerous: true)]
    public function createBlockType(
        string $name,
        ?string $handle = null,
        ?string $fieldHandle = null,
        ?string $existingFields = null,
        ?string $newFields = null,
        ?string $childBlockTypes = null,
        bool $scaffoldTemplate = true,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use (
            $name,
            $handle,
            $fieldHandle,
            $existingFields,
            $newFields,
            $childBlockTypes,
            $scaffoldTemplate,
            $dryRun,
        ): array {
            $this->assertNeoAvailable();

            $field = $this->resolveBuilderField(null, $fieldHandle);
            $plan = $this->planBlockType(
                $field,
                $name,
                $handle,
                $existingFields,
                $newFields,
                $childBlockTypes,
                $scaffoldTemplate,
            );

            if ($dryRun) {
                return Response::success([
                    'dryRun' => true,
                    'blockType' => $this->describeBlockType($field, $plan),
                    'fields' => $this->describeAttachments($plan['attachments']),
                    'template' => $plan['template'] ?? ['skipped' => true],
                ]);
            }

            $attachments = array_map($this->materializeAttachment(...), $plan['attachments']);
            $layout = $this->buildFieldLayout($attachments);
            $blockType = $this->buildBlockType($field, $plan, $layout);
            $this->saveBlockType($blockType);

            return Response::success([
                'blockType' => [
                    'id' => $blockType->id ?? null,
                    ...$this->describeBlockType($field, $plan),
                ],
                'fields' => $this->describeAttachments($attachments),
                'template' => $this->writeTemplate($plan['template']),
            ]);
        });
    }

    /**
     * Validate all inputs and build the full scaffold plan (no writes).
     *
     * @return array{name: string, handle: string, sortOrder: int, childBlockTypes: array<int, string>, attachments: array<int, array<string, mixed>>, template: array{path: string, content: string}|null}
     * @throws ToolCallException
     */
    private function planBlockType(
        NeoField $field,
        string $name,
        ?string $handle,
        ?string $existingFields,
        ?string $newFields,
        ?string $childBlockTypes,
        bool $scaffoldTemplate,
    ): array {
        $blockTypeHandle = $this->resolveBlockTypeHandle($handle, $name);
        $this->assertBlockTypeHandleAvailable($field, $blockTypeHandle);

        $children = $this->decodeStringList($childBlockTypes, 'childBlockTypes');
        $this->assertChildTypesKnown($field, $blockTypeHandle, $children);

        $attachments = $this->dedupeAttachments([
            ...$this->planExistingAttachments($this->decodeStringList($existingFields, 'existingFields')),
            ...$this->planNewFields($this->decodeNewFields($newFields)),
        ]);

        return [
            'name' => $name,
            'handle' => $blockTypeHandle,
            'sortOrder' => count($field->getBlockTypes()) + 1,
            'childBlockTypes' => $children,
            'attachments' => $attachments,
            'template' => $scaffoldTemplate
                ? $this->planTemplate($blockTypeHandle, $attachments, $children)
                : null,
        ];
    }

    /**
     * Resolve the block type handle: explicit param, or camelCase of the name.
     *
     * @throws ToolCallException
     */
    private function resolveBlockTypeHandle(?string $handle, string $name): string {
        $resolved = $handle !== null && trim($handle) !== ''
            ? trim($handle)
            : StringHelper::toCamelCase($name);

        $this->assertValidHandle($resolved, 'Block type');

        return $resolved;
    }

    /**
     * @throws ToolCallException
     */
    private function assertValidHandle(string $handle, string $label): void {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $handle) !== 1) {
            throw new ToolCallException(
                "{$label} handle '{$handle}' is invalid: handles must start with a letter and contain only letters, digits and underscores.",
            );
        }
    }

    /**
     * @throws ToolCallException
     */
    private function assertBlockTypeHandleAvailable(NeoField $field, string $handle): void {
        foreach ($field->getBlockTypes() as $blockType) {
            if ($blockType->handle === $handle) {
                throw new ToolCallException(
                    "Block type '{$handle}' already exists on field '{$field->handle}'.",
                );
            }
        }
    }

    /**
     * Assert every childBlockTypes handle is an existing block type on the
     * field (or the new block type itself, for self-nesting containers).
     *
     * @param array<int, string> $children
     * @throws ToolCallException
     */
    private function assertChildTypesKnown(NeoField $field, string $newHandle, array $children): void {
        if ($children === []) {
            return;
        }

        $known = array_map(
            static fn (object $blockType): string => (string) $blockType->handle,
            $field->getBlockTypes(),
        );
        $known[] = $newHandle;

        $unknown = array_values(array_diff($children, $known));
        if ($unknown !== []) {
            throw new ToolCallException(
                'Unknown childBlockTypes: ' . implode(', ', $unknown)
                . '. Available block types: ' . implode(', ', $known) . '.',
            );
        }
    }

    /**
     * Decode a JSON array of non-empty strings.
     *
     * @return array<int, string>
     * @throws ToolCallException
     */
    private function decodeStringList(?string $json, string $param): array {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ToolCallException("{$param} must be a JSON array of strings.");
        }

        return array_map(
            static function (mixed $item) use ($param): string {
                if (!is_string($item) || trim($item) === '') {
                    throw new ToolCallException("{$param} must be a JSON array of non-empty strings.");
                }

                return trim($item);
            },
            $decoded,
        );
    }

    /**
     * Decode and validate the newFields JSON payload.
     *
     * @return array<int, array{name: string, handle: string|null, type: string, options: array<int, array<string, mixed>>, required: bool}>
     * @throws ToolCallException
     */
    private function decodeNewFields(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ToolCallException(
                'newFields must be a JSON array of {name, handle?, type, options?, required?} objects.',
            );
        }

        return array_map($this->normalizeNewFieldSpec(...), $decoded, array_keys($decoded));
    }

    /**
     * @return array{name: string, handle: string|null, type: string, options: array<int, array<string, mixed>>, required: bool}
     * @throws ToolCallException
     */
    private function normalizeNewFieldSpec(mixed $spec, int $index): array {
        if (!is_array($spec)) {
            throw new ToolCallException("newFields[{$index}] must be a JSON object.");
        }

        $name = $spec['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ToolCallException("newFields[{$index}] requires a non-empty name.");
        }

        $type = $spec['type'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            throw new ToolCallException(
                "newFields[{$index}] requires a type: one of "
                . implode(', ', [...array_keys(self::FIELD_TYPE_MAP), 'richText']) . '.',
            );
        }

        $handle = $spec['handle'] ?? null;
        if ($handle !== null && (!is_string($handle) || trim($handle) === '')) {
            throw new ToolCallException("newFields[{$index}] handle must be a non-empty string when given.");
        }

        return [
            'name' => trim($name),
            'handle' => is_string($handle) ? trim($handle) : null,
            'type' => trim($type),
            'options' => $this->normalizeOptions($spec['options'] ?? null, $index, trim($type)),
            'required' => (bool) ($spec['required'] ?? false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws ToolCallException
     */
    private function normalizeOptions(mixed $options, int $index, string $type): array {
        if ($type === 'dropdown' && (!is_array($options) || $options === [])) {
            throw new ToolCallException(
                "newFields[{$index}]: dropdown fields require a non-empty options array of {label, value} objects.",
            );
        }

        if (!is_array($options)) {
            return [];
        }

        return array_map(
            static function (mixed $option) use ($index): array {
                if (!is_array($option) || !isset($option['label'], $option['value'])) {
                    throw new ToolCallException(
                        "newFields[{$index}]: each option must be a {label, value} object.",
                    );
                }

                return [
                    'label' => (string) $option['label'],
                    'value' => (string) $option['value'],
                    'default' => (bool) ($option['default'] ?? false),
                ];
            },
            array_values($options),
        );
    }

    /**
     * Plan attachments for existing fields requested by handle.
     *
     * @param array<int, string> $handles
     * @return array<int, array<string, mixed>>
     * @throws ToolCallException
     */
    private function planExistingAttachments(array $handles): array {
        return array_map(
            fn (string $fieldHandle): array => [
                'source' => 'existing',
                'handle' => $fieldHandle,
                'required' => false,
                'field' => $this->resolveExistingField($fieldHandle),
                'create' => null,
                'match' => null,
            ],
            $handles,
        );
    }

    /**
     * @throws ToolCallException
     */
    private function resolveExistingField(string $handle): FieldInterface {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if ($field !== null) {
            return $field;
        }

        $candidates = FieldMatcher::closeCandidates($handle, array_column($this->existingFieldSummaries(), 'handle'));
        $hint = $candidates === [] ? '' : ' Close matches: ' . implode(', ', $candidates) . '.';

        throw new ToolCallException("Field '{$handle}' not found.{$hint}");
    }

    /**
     * Plan attachments for requested new fields, reusing an existing field
     * when one already matches (same handle, or same type + similar name).
     *
     * @param array<int, array{name: string, handle: string|null, type: string, options: array<int, array<string, mixed>>, required: bool}> $specs
     * @return array<int, array<string, mixed>>
     * @throws ToolCallException
     */
    private function planNewFields(array $specs): array {
        if ($specs === []) {
            return [];
        }

        $summaries = $this->existingFieldSummaries();

        return array_map(
            fn (array $spec): array => $this->planNewField($spec, $summaries),
            $specs,
        );
    }

    /**
     * @param array{name: string, handle: string|null, type: string, options: array<int, array<string, mixed>>, required: bool} $spec
     * @param array<int, array{handle: string, name: string, class: string}> $summaries
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    private function planNewField(array $spec, array $summaries): array {
        $class = $this->resolveFieldTypeClass($spec['type']);
        $specHandle = $spec['handle'] ?? StringHelper::toCamelCase($spec['name']);
        $this->assertValidHandle($specHandle, "newFields '{$spec['name']}'");

        $match = FieldMatcher::matchExisting($specHandle, $spec['name'], $class, $summaries);

        if ($match === null) {
            return [
                'source' => 'create',
                'handle' => $specHandle,
                'required' => $spec['required'],
                'field' => null,
                'create' => [
                    'name' => $spec['name'],
                    'handle' => $specHandle,
                    'class' => $class,
                    'options' => $spec['options'],
                ],
                'match' => null,
            ];
        }

        return [
            'source' => 'matched',
            'handle' => $match['handle'],
            'required' => $spec['required'],
            'field' => $this->resolveExistingField($match['handle']),
            'create' => null,
            'match' => [
                'requestedName' => $spec['name'],
                'requestedHandle' => $specHandle,
                'requestedType' => $class,
                'matchedHandle' => $match['handle'],
                'matchedName' => $match['name'],
                'matchedType' => $match['class'],
                'reason' => $match['reason'],
            ],
        ];
    }

    /**
     * @throws ToolCallException
     */
    private function resolveFieldTypeClass(string $type): string {
        if ($type === 'richText') {
            return $this->richTextFieldClass();
        }

        $class = self::FIELD_TYPE_MAP[$type] ?? null;
        if ($class === null) {
            throw new ToolCallException(
                "Unknown newFields type '{$type}'. Supported types: "
                . implode(', ', [...array_keys(self::FIELD_TYPE_MAP), 'richText']) . '.',
            );
        }

        return $class;
    }

    /**
     * @throws ToolCallException
     */
    private function richTextFieldClass(): string {
        if (!class_exists(self::RICH_TEXT_FIELD_CLASS)) {
            throw new ToolCallException(
                "newFields type 'richText' requires the CKEditor plugin ("
                . self::RICH_TEXT_FIELD_CLASS . '), which is not installed.',
            );
        }

        return self::RICH_TEXT_FIELD_CLASS;
    }

    /**
     * Summarize all existing fields for matching and candidate suggestions.
     *
     * @return array<int, array{handle: string, name: string, class: string}>
     */
    private function existingFieldSummaries(): array {
        return array_map(
            static fn (FieldInterface $field): array => [
                'handle' => (string) $field->handle,
                'name' => (string) $field->name,
                'class' => $field::class,
            ],
            Craft::$app->getFields()->getAllFields(),
        );
    }

    /**
     * Drop duplicate attachments by field handle (first occurrence wins).
     *
     * @param array<int, array<string, mixed>> $attachments
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAttachments(array $attachments): array {
        $seen = [];
        $result = [];

        foreach ($attachments as $item) {
            $itemHandle = (string) $item['handle'];
            if (isset($seen[$itemHandle])) {
                continue;
            }

            $seen[$itemHandle] = true;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Plan the template stub: resolve the path (erroring on conflicts — an
     * existing stub is never overwritten) and render the content.
     *
     * @param array<int, array<string, mixed>> $attachments
     * @param array<int, string> $children
     * @return array{path: string, content: string}
     * @throws ToolCallException
     */
    private function planTemplate(string $handle, array $attachments, array $children): array {
        $path = $this->stubFilePath($handle);

        if (is_file($path)) {
            throw new ToolCallException(
                "Template stub already exists at {$path} and is never overwritten. "
                . 'Pass scaffoldTemplate: false to create the block type without a stub.',
            );
        }

        $fieldHandles = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['handle'],
            $attachments,
        )));

        return [
            'path' => $path,
            'content' => BlockTypeStub::render($handle, $fieldHandles, $children, $this->overrideTemplate()),
        ];
    }

    private function stubFilePath(string $handle): string {
        return Craft::$app->getPath()->getSiteTemplatesPath()
            . DIRECTORY_SEPARATOR . 'body_blocks'
            . DIRECTORY_SEPARATOR . $handle . '.twig';
    }

    /**
     * Load the custom stub template configured via the templateStubPath
     * setting, or null to use the built-in stub.
     *
     * @throws ToolCallException
     */
    private function overrideTemplate(): ?string {
        $path = Mcp::settings()->templateStubPath;

        if ($path === null || trim($path) === '') {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new ToolCallException(
                "The templateStubPath setting ('{$path}') is not a readable file.",
            );
        }

        return (string) file_get_contents($path);
    }

    /**
     * Describe the planned block type for responses.
     *
     * @param array{name: string, handle: string, sortOrder: int, childBlockTypes: array<int, string>} $plan
     * @return array<string, mixed>
     */
    private function describeBlockType(NeoField $field, array $plan): array {
        return [
            'name' => $plan['name'],
            'handle' => $plan['handle'],
            'fieldHandle' => $field->handle,
            'sortOrder' => $plan['sortOrder'],
            'topLevel' => true,
            'childBlockTypes' => $plan['childBlockTypes'],
        ];
    }

    /**
     * Group attachments into attach / create / matched report lists.
     *
     * @param array<int, array<string, mixed>> $attachments
     * @return array<string, mixed>
     */
    private function describeAttachments(array $attachments): array {
        $attach = [];
        $create = [];
        $matched = [];

        foreach ($attachments as $item) {
            if ($item['source'] === 'create') {
                $create[] = $this->describeCreateItem($item);
                continue;
            }

            if ($item['match'] !== null) {
                $matched[] = $item['match'];
            }

            $attach[] = [
                'handle' => $item['handle'],
                'type' => is_object($item['field']) ? $item['field']::class : null,
                'required' => $item['required'],
            ];
        }

        return ['attach' => $attach, 'create' => $create, 'matched' => $matched];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function describeCreateItem(array $item): array {
        $create = is_array($item['create']) ? $item['create'] : [];
        $field = $item['field'];

        return [
            'name' => $create['name'] ?? null,
            'handle' => $item['handle'],
            'type' => $create['class'] ?? null,
            'required' => $item['required'],
            'id' => is_object($field) ? ($field->id ?? null) : null,
        ];
    }

    /**
     * Create the planned field for a 'create' attachment; pass-through
     * otherwise.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    private function materializeAttachment(array $item): array {
        if ($item['source'] !== 'create') {
            return $item;
        }

        $item['field'] = $this->createField(is_array($item['create']) ? $item['create'] : []);

        return $item;
    }

    /**
     * @param array<string, mixed> $create
     * @throws ToolCallException
     */
    private function createField(array $create): FieldInterface {
        $class = (string) $create['class'];

        /** @var Field $field */
        $field = new $class();
        $field->name = (string) $create['name'];
        $field->handle = (string) $create['handle'];
        $this->applyOptions($field, is_array($create['options'] ?? null) ? $create['options'] : []);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new ToolCallException(
                "Failed to create field '{$field->handle}': " . json_encode($field->getErrors()),
            );
        }

        return $field;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function applyOptions(Field $field, array $options): void {
        if ($options === [] || !property_exists($field, 'options')) {
            return;
        }

        $field->options = $options;
    }

    /**
     * Build the block type's field layout from the ordered attachments.
     *
     * @param array<int, array<string, mixed>> $attachments
     * @throws ToolCallException
     */
    private function buildFieldLayout(array $attachments): FieldLayout {
        $layout = new FieldLayout(['type' => Block::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);

        $tab->setElements(array_map(
            static function (array $item): CustomField {
                $field = $item['field'];
                if (!$field instanceof FieldInterface) {
                    throw new ToolCallException(
                        "Unable to resolve field '{$item['handle']}' for the block type field layout.",
                    );
                }

                return new CustomField($field, ['required' => (bool) $item['required']]);
            },
            $attachments,
        ));
        $layout->setTabs([$tab]);

        return $layout;
    }

    /**
     * Build the (unsaved) Neo BlockType model.
     *
     * @param array{name: string, handle: string, sortOrder: int, childBlockTypes: array<int, string>} $plan
     */
    private function buildBlockType(NeoField $field, array $plan, FieldLayout $layout): BlockType {
        $blockType = new BlockType();
        $blockType->fieldId = $field->id;
        $blockType->name = $plan['name'];
        $blockType->handle = $plan['handle'];
        $blockType->enabled = true;
        $blockType->topLevel = true;
        $blockType->sortOrder = $plan['sortOrder'];

        $this->applyChildBlocks($blockType, $plan['childBlockTypes']);
        $this->applyFieldLayout($blockType, $layout);

        return $blockType;
    }

    /**
     * @param array<int, string> $children
     */
    private function applyChildBlocks(object $blockType, array $children): void {
        if ($children === []) {
            return;
        }

        $blockType->childBlocks = $children;
    }

    /**
     * Attach the field layout to the block type: via its FieldLayoutBehavior
     * when available (Neo persists it on save), otherwise by saving the
     * layout directly and linking its ID.
     */
    private function applyFieldLayout(object $blockType, FieldLayout $layout): void {
        if (method_exists($blockType, 'hasMethod') && $blockType->hasMethod('setFieldLayout')) {
            $blockType->setFieldLayout($layout);

            return;
        }

        Craft::$app->getFields()->saveLayout($layout);
        $blockType->fieldLayoutId = $layout->id;
    }

    /**
     * Save the block type through Neo's block types service so Neo writes
     * project config (and Craft writes the YAML) itself.
     *
     * @throws ToolCallException
     */
    private function saveBlockType(BlockType $blockType): void {
        if ($this->invokeBlockTypeSave($this->blockTypesService(), $blockType)) {
            return;
        }

        throw new ToolCallException(
            'Failed to save block type: ' . json_encode($blockType->getErrors()),
        );
    }

    /**
     * @throws ToolCallException
     */
    private function blockTypesService(): object {
        $service = Neo::getInstance()?->get('blockTypes', false);

        if (!is_object($service)) {
            throw new ToolCallException('Unable to resolve the Neo block types service.');
        }

        return $service;
    }

    /**
     * Invoke Neo's block type save, tolerating service method renames across
     * Neo versions.
     *
     * @throws ToolCallException
     */
    private function invokeBlockTypeSave(object $service, BlockType $blockType): bool {
        if (method_exists($service, 'save')) {
            return (bool) $service->save($blockType);
        }

        if (method_exists($service, 'saveBlockType')) {
            return (bool) $service->saveBlockType($blockType);
        }

        throw new ToolCallException('The installed Neo version does not expose a block type save method.');
    }

    /**
     * Write the planned template stub (conflicts were rejected at plan time,
     * re-checked here — an existing file is never overwritten).
     *
     * @param array{path: string, content: string}|null $template
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    private function writeTemplate(?array $template): array {
        if ($template === null) {
            return ['skipped' => true];
        }

        if (is_file($template['path'])) {
            throw new ToolCallException(
                "Template stub already exists at {$template['path']} and is never overwritten.",
            );
        }

        CraftFileHelper::writeToFile($template['path'], $template['content']);

        return ['path' => $template['path'], 'written' => true];
    }
}
