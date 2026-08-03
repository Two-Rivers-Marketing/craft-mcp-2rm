<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ElementHelper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use twoRivers\craft\Mcp\support\Serializer;

/**
 * Entry-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryTools {
    /**
     * List entries with optional filters.
     */
    #[McpTool(
        name: 'list_entries',
        description: 'List entries from Craft CMS. Filter by section, type, status, limit, offset. Returns entry data including custom fields.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listEntries(
        ?string $section = null,
        ?string $type = null,
        ?string $status = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $status, $limit, $offset): array {
            $query = Entry::find()
                ->limit($limit)
                ->offset($offset);

            $this->applyFilters($query, [
                'section' => $section,
                'type' => $type,
                'status' => $status ?? null, // null = all statuses
            ]);

            $entries = $query->all();
            $results = array_map($this->serializeEntry(...), $entries);

            return Response::paginated('entries', $results, (int) $query->count(), $limit, $offset);
        });
    }

    /**
     * Get a single entry by ID or slug.
     */
    #[McpTool(
        name: 'get_entry',
        description: 'Get a single entry by ID or slug. Returns full entry data including all custom fields.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getEntry(?int $id = null, ?string $slug = null, ?string $section = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $slug, $section): array {
            if ($id === null && $slug === null) {
                throw new ToolCallException('Either id or slug must be provided');
            }

            $query = Entry::find()->status(null);
            $this->applyFilters($query, [
                'id' => $id,
                'slug' => $slug,
                'section' => $section,
            ]);

            $entry = $query->one();

            if ($entry === null) {
                throw new ToolCallException('Entry not found');
            }

            return Response::found('entry', $this->serializeEntry($entry));
        });
    }

    /**
     * Create a new entry.
     */
    #[McpTool(
        name: 'create_entry',
        description: 'Create a new entry in Craft CMS. Requires section handle, entry type handle, title, and optionally custom field values as JSON.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createEntry(
        string $section,
        string $type,
        string $title,
        ?string $slug = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $title, $slug, $fields): array {
            $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);
            if ($sectionModel === null) {
                throw new ToolCallException("Section '{$section}' not found");
            }

            $entryType = $this->findEntryType($sectionModel, $type);
            if ($entryType === null) {
                throw new ToolCallException("Entry type '{$type}' not found in section '{$section}'");
            }

            $fieldValues = $this->parseFieldsJson($fields);
            if ($fieldValues === false) {
                throw new ToolCallException('Invalid JSON in fields parameter');
            }

            $entry = new Entry();
            $entry->sectionId = $sectionModel->id;
            $entry->typeId = $entryType->id;
            $entry->title = $title;
            $entry->slug = $slug;
            $entry->authorId = $this->getAuthorId();

            if ($fieldValues !== null) {
                $entry->setFieldValues($fieldValues);
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolCallException('Failed to save entry: ' . json_encode($entry->getErrors()));
            }

            $writtenHandles = $fieldValues !== null ? array_keys($fieldValues) : null;

            return Response::success(['entry' => $this->serializeEntry($entry, $writtenHandles)]);
        });
    }

    /**
     * Update an existing entry.
     */
    #[McpTool(
        name: 'update_entry',
        description: 'Update an existing entry by ID. Can update title, slug, status, and custom field values (as JSON).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateEntry(
        int $id,
        ?string $title = null,
        ?string $slug = null,
        ?string $status = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $title, $slug, $status, $fields): array {
            $entry = Entry::find()->id($id)->status(null)->one();

            if ($entry === null) {
                throw new ToolCallException("Entry with ID {$id} not found");
            }

            $fieldValues = $this->parseFieldsJson($fields);
            if ($fieldValues === false) {
                throw new ToolCallException('Invalid JSON in fields parameter');
            }

            if ($title !== null) {
                $entry->title = $title;
            }
            if ($slug !== null) {
                $entry->slug = $slug;
            }
            if ($status !== null) {
                $entry->enabled = ($status === 'live' || $status === 'enabled');
            }
            if ($fieldValues !== null) {
                $entry->setFieldValues($fieldValues);
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolCallException('Failed to save entry: ' . json_encode($entry->getErrors()));
            }

            $writtenHandles = $fieldValues !== null ? array_keys($fieldValues) : null;

            return Response::success(['entry' => $this->serializeEntry($entry, $writtenHandles)]);
        });
    }

    /**
     * Delete an entry by ID.
     */
    #[McpTool(
        name: 'delete_entry',
        description: 'Delete an entry by ID. Pass dryRun: true to preview what would be deleted without deleting. Performs a hard delete (not soft-delete/trash).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function deleteEntry(
        int $id,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $dryRun): array {
            $entry = Entry::find()->id($id)->status(null)->one();

            if ($entry === null) {
                throw new ToolCallException("Entry with ID {$id} not found");
            }

            $summary = [
                'id' => $entry->id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'sectionHandle' => $entry->getSection()?->handle, // @phpstan-ignore nullsafe.neverNull
                'typeHandle' => $entry->getType()?->handle, // @phpstan-ignore nullsafe.neverNull
                'url' => $entry->getUrl(),
            ];

            if ($dryRun) {
                return Response::success(['dryRun' => true, 'entry' => $summary]);
            }

            if (!Craft::$app->getElements()->deleteElement($entry, true)) {
                throw new ToolCallException('Failed to delete entry: ' . json_encode($entry->getErrors()));
            }

            return Response::success(['deleted' => true, 'entry' => $summary]);
        });
    }

    /**
     * Create a draft of an existing entry.
     */
    #[McpTool(
        name: 'create_draft',
        description: 'Create a draft of an EXISTING entry so edits can be staged without changing the live entry. '
            . 'Requires entryId of an entry that already exists — this tool cannot create a brand-new entry as a draft; '
            . 'call create_entry first, then create_draft with the returned id. '
            . 'Optionally set the draft name, draft notes, a new title, and custom field values as JSON. '
            . 'Returns the draft including its draftId — pass that draftId (not the entry id) to publish_draft.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createDraft(
        int $entryId,
        ?string $name = null,
        ?string $notes = null,
        ?string $title = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($entryId, $name, $notes, $title, $fields): array {
            $entry = Entry::find()->id($entryId)->status(null)->one();

            if ($entry === null) {
                throw new ToolCallException("Entry with ID {$entryId} not found");
            }

            if ($entry->getIsDraft() || $entry->getIsRevision()) {
                throw new ToolCallException("Entry with ID {$entryId} is itself a draft or revision; drafts can only be created from a canonical entry");
            }

            $fieldValues = $this->parseFieldsJson($fields);
            if ($fieldValues === false) {
                throw new ToolCallException('Invalid JSON in fields parameter');
            }

            $draft = Craft::$app->getDrafts()->createDraft($entry, $this->getAuthorId(), $name, $notes);

            if ($title !== null) {
                $draft->title = $title;
            }
            if ($fieldValues !== null) {
                $draft->setFieldValues($fieldValues);
            }

            $hasEdits = $title !== null || $fieldValues !== null;
            if ($hasEdits && !Craft::$app->getElements()->saveElement($draft)) {
                throw new ToolCallException('Failed to save draft: ' . json_encode($draft->getErrors()));
            }

            $writtenHandles = $fieldValues !== null ? array_keys($fieldValues) : null;

            return Response::success(['draft' => $this->serializeDraft($draft, $writtenHandles)]);
        });
    }

    /**
     * Apply a draft to its canonical entry.
     */
    #[McpTool(
        name: 'publish_draft',
        description: 'Apply a draft to its canonical entry and delete the draft. '
            . 'Requires draftId — the draft id returned by create_draft or list_drafts, NOT the entry id. '
            . 'Pass dryRun: true to preview the draft that would be published without applying it.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function publishDraft(
        int $draftId,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($draftId, $dryRun): array {
            $draft = $this->findDraft($draftId);

            if ($draft === null) {
                throw new ToolCallException("Draft with ID {$draftId} not found");
            }

            if ($dryRun) {
                return Response::success(['dryRun' => true, 'draft' => $this->serializeDraft($draft)]);
            }

            $canonical = Craft::$app->getDrafts()->applyDraft($draft);

            return Response::success([
                'published' => true,
                'draftId' => $draftId,
                'entry' => $this->serializeEntry($canonical),
            ]);
        });
    }

    /**
     * List drafts, optionally scoped to one canonical entry.
     */
    #[McpTool(
        name: 'list_drafts',
        description: 'List entry drafts, most recently updated first. '
            . 'Pass entryId to list only drafts of that canonical entry, or omit it to list drafts across all entries. '
            . 'Includes provisional (control-panel auto-save) drafts. Each result carries a draftId for publish_draft.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listDrafts(
        ?int $entryId = null,
        int $limit = 20,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($entryId, $limit): array {
            $query = Entry::find()
                ->drafts()
                ->provisionalDrafts(null)
                ->status(null)
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->limit($limit);

            if ($entryId !== null) {
                $query->draftOf($entryId);
            }

            $results = array_map($this->serializeDraft(...), $query->all());

            return Response::list('drafts', $results, ['total' => (int) $query->count()]);
        });
    }

    /**
     * Find a draft entry by its draft ID, including provisional drafts.
     */
    private function findDraft(int $draftId): ?Entry {
        return Entry::find()
            ->draftId($draftId)
            ->drafts()
            ->provisionalDrafts(null)
            ->status(null)
            ->one();
    }

    /**
     * Apply non-null filters to a query.
     */
    private function applyFilters(mixed $query, array $filters): void {
        foreach ($filters as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }
    }

    /**
     * Find entry type by handle in section.
     */
    private function findEntryType(mixed $section, string $handle): ?object {
        foreach ($section->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        return null;
    }

    /**
     * Parse fields JSON parameter.
     *
     * @return array|null|false null if empty, false if invalid JSON, array if valid
     */
    private function parseFieldsJson(?string $fields): array|null|false {
        if ($fields === null) {
            return null;
        }

        $decoded = json_decode($fields, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
    }

    /**
     * Get author ID for new entries.
     */
    private function getAuthorId(): ?int {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            $user = User::find()->admin()->one();
        }

        return $user?->id;
    }

    /**
     * Serialize an entry to array.
     *
     * @param list<string>|null $onlyFields When set, only these field handles are serialized (compact response for create/update).
     */
    private function serializeEntry(Entry $entry, ?array $onlyFields = null): array {
        $data = [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'sectionId' => $entry->sectionId,
            'sectionHandle' => $entry->getSection()?->handle, // @phpstan-ignore nullsafe.neverNull
            'typeId' => $entry->typeId,
            'typeHandle' => $entry->getType()?->handle, // @phpstan-ignore nullsafe.neverNull
            'authorId' => $entry->authorId,
            'postDate' => $entry->postDate?->format('c'),
            'expiryDate' => $entry->expiryDate?->format('c'),
            'dateCreated' => $entry->dateCreated?->format('c'),
            'dateUpdated' => $entry->dateUpdated?->format('c'),
            'url' => $entry->getUrl(),
        ];

        $fieldLayout = $entry->getFieldLayout();
        if ($fieldLayout !== null) {
            $data['fields'] = $this->serializeFields($entry, $fieldLayout, $onlyFields);
        }

        return $data;
    }

    /**
     * Serialize a draft entry: the entry data plus its draft metadata.
     *
     * @param list<string>|null $onlyFields When set, only these field handles are serialized.
     */
    private function serializeDraft(Entry $draft, ?array $onlyFields = null): array {
        $meta = [
            'draftId' => $draft->draftId,
            'canonicalId' => $draft->getCanonicalId(),
            'draftName' => null,
            'draftNotes' => null,
            'isProvisionalDraft' => $draft->isProvisionalDraft,
            'isUnpublishedDraft' => $draft->getIsUnpublishedDraft(),
            'isOutdated' => ElementHelper::isOutdated($draft),
        ];

        $behavior = $draft->getBehavior('draft');
        if ($behavior instanceof DraftBehavior) {
            $meta['draftName'] = $behavior->draftName;
            $meta['draftNotes'] = $behavior->draftNotes;
        }

        return [...$this->serializeEntry($draft, $onlyFields), ...$meta];
    }

    /**
     * Serialize custom field values.
     *
     * @param list<string>|null $onlyFields When set, only serialize these handles.
     */
    private function serializeFields(Entry $entry, mixed $fieldLayout, ?array $onlyFields = null): array {
        $fieldValues = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($onlyFields !== null && !in_array($field->handle, $onlyFields, true)) {
                continue;
            }
            $fieldValues[$field->handle] = Serializer::serialize($entry->getFieldValue($field->handle));
        }

        return $fieldValues;
    }
}
