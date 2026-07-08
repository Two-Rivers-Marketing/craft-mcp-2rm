<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use twoRivers\craft\Mcp\support\Serializer;

/**
 * Asset-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class AssetTools {
    /**
     * List assets with optional filters.
     */
    #[McpTool(
        name: 'list_assets',
        description: 'List assets from Craft CMS. Filter by volume, folder, kind (image, video, pdf, etc.), filename.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssets(
        ?string $volume = null,
        ?int $folderId = null,
        ?string $kind = null,
        ?string $filename = null,
        int $limit = 50,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($volume, $folderId, $kind, $filename, $limit, $offset): array {
            $query = Asset::find()
                ->limit($limit)
                ->offset($offset);

            if ($volume !== null) {
                $query->volume($volume);
            }

            if ($folderId !== null) {
                $query->folderId($folderId);
            }

            if ($kind !== null) {
                $query->kind($kind);
            }

            if ($filename !== null) {
                $query->filename('*' . $filename . '*');
            }

            $assets = $query->all();
            $results = [];

            foreach ($assets as $asset) {
                $results[] = $this->serializeAsset($asset);
            }

            return [
                'count' => count($results),
                'total' => $query->count(),
                'limit' => $limit,
                'offset' => $offset,
                'assets' => $results,
            ];
        });
    }

    /**
     * Get a single asset by ID.
     */
    #[McpTool(
        name: 'get_asset',
        description: 'Get a single asset by ID with full metadata',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getAsset(int $id, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id): array {
            $asset = Asset::find()->id($id)->one();

            if ($asset === null) {
                throw new ToolCallException("Asset with ID {$id} not found");
            }

            return [
                'found' => true,
                'asset' => $this->serializeAsset($asset, true),
            ];
        });
    }

    /**
     * List all asset volumes.
     */
    #[McpTool(
        name: 'list_volumes',
        description: 'List all asset volumes (storage locations) in Craft CMS',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listVolumes(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $volumes = Craft::$app->getVolumes()->getAllVolumes();
            $results = [];

            foreach ($volumes as $volume) {
                $results[] = [
                    'id' => $volume->id,
                    'handle' => $volume->handle,
                    'name' => $volume->name,
                    'type' => $volume->getFs()::class,
                    'hasUrls' => $volume->getFs()->hasUrls,
                    'rootUrl' => $volume->getFs()->hasUrls ? $volume->getFs()->getRootUrl() : null,
                ];
            }

            return [
                'count' => count($results),
                'volumes' => $results,
            ];
        });
    }

    /**
     * List folders in a volume.
     */
    #[McpTool(
        name: 'list_asset_folders',
        description: 'List asset folders in a volume',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssetFolders(?string $volume = null, ?int $parentId = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($volume, $parentId): array {
            $assetsService = Craft::$app->getAssets();

            $folders = $this->getAssetFolders($assetsService, $volume, $parentId);
            if ($folders === null) {
                throw new ToolCallException("Volume '{$volume}' not found");
            }

            $results = [];
            foreach ($folders as $folder) {
                $results[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'path' => $folder->path,
                    'volumeId' => $folder->volumeId,
                    'parentId' => $folder->parentId,
                ];
            }

            return [
                'count' => count($results),
                'folders' => $results,
            ];
        });
    }

    /**
     * Upload a local file into a volume.
     */
    #[McpTool(
        name: 'upload_asset',
        description: 'Upload a file from a server-visible local path into a Craft CMS volume. `path` must be readable on the server running Craft, not the MCP client. Optionally target a subfolder via `folder` (a path within the volume, e.g. "products/2026"), which is created if missing. Returns the created asset\'s ID, filename, url, kind, and dimensions (if an image).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function uploadAsset(
        string $path,
        string $volume,
        ?string $folder = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($path, $volume, $folder): array {
            $this->assertReadableFile($path);

            $volumeModel = Craft::$app->getVolumes()->getVolumeByHandle($volume);
            if ($volumeModel === null) {
                throw new ToolCallException("Volume '{$volume}' not found. {$this->availableVolumesMessage()}");
            }

            $targetFolder = $this->resolveTargetFolder($volumeModel, $folder);
            $tempPath = $this->copyToTempPath($path);
            $asset = $this->buildAsset($path, $tempPath, $targetFolder);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new ToolCallException('Failed to save asset: ' . json_encode($asset->getErrors()));
            }

            return Response::success(['asset' => $this->serializeAsset($asset)]);
        });
    }

    /**
     * Assert that a server-local path exists and is a readable file.
     *
     * @throws ToolCallException
     */
    private function assertReadableFile(string $path): void {
        if (!is_file($path) || !is_readable($path)) {
            throw new ToolCallException("File not found or not readable at path: {$path}");
        }
    }

    /**
     * Resolve the target folder for an upload: the volume's root folder by
     * default, or a subfolder path (created if missing) when given.
     *
     * @throws ToolCallException
     */
    private function resolveTargetFolder(Volume $volumeModel, ?string $folder): VolumeFolder {
        if ($folder === null || trim($folder, '/') === '') {
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volumeModel->id);
            if ($rootFolder === null) {
                throw new ToolCallException("Could not resolve root folder for volume '{$volumeModel->handle}'");
            }

            return $rootFolder;
        }

        return Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folder, $volumeModel, false);
    }

    /**
     * Copy the source file to a Craft-managed temp path. Craft's asset save
     * flow consumes (and may move) the temp file, so the caller's original
     * file must never be passed directly as tempFilePath.
     *
     * @throws ToolCallException
     */
    private function copyToTempPath(string $path): string {
        $tempPath = Craft::$app->getPath()->getTempAssetUploadsPath()
            . DIRECTORY_SEPARATOR . FileHelper::uniqueName(basename($path));

        if (!copy($path, $tempPath)) {
            throw new ToolCallException("Failed to copy file to temp path for upload: {$path}");
        }

        return $tempPath;
    }

    /**
     * Build the Asset element for a new upload.
     */
    private function buildAsset(string $sourcePath, string $tempPath, VolumeFolder $targetFolder): Asset {
        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->filename = $this->sanitizeFilename(basename($sourcePath));
        $asset->newFolderId = $targetFolder->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        return $asset;
    }

    /**
     * Strip directory components and disallowed characters from a filename.
     */
    private function sanitizeFilename(string $filename): string {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);

        if (!is_string($sanitized) || $sanitized === '') {
            return 'upload';
        }

        return $sanitized;
    }

    /**
     * Build an error-message fragment listing available volume handles.
     */
    private function availableVolumesMessage(): string {
        $handles = array_map(
            static fn ($vol) => $vol->handle,
            Craft::$app->getVolumes()->getAllVolumes(),
        );

        return 'Available volumes: ' . implode(', ', $handles);
    }

    /**
     * Get asset folders based on volume and parent ID.
     *
     * @return VolumeFolder[]|null Null if volume not found
     */
    private function getAssetFolders(
        Assets $assetsService,
        ?string $volume,
        ?int $parentId,
    ): ?array {
        if ($volume === null) {
            return $this->getAllRootFolders($assetsService);
        }

        $volumeModel = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        if ($volumeModel === null) {
            return null;
        }

        if ($parentId !== null) {
            return $assetsService->findFolders(['parentId' => $parentId]);
        }

        $rootFolder = $assetsService->getRootFolderByVolumeId($volumeModel->id);
        if ($rootFolder === null) {
            return [];
        }

        return $assetsService->findFolders(['parentId' => $rootFolder->id]);
    }

    /**
     * Get all root folders across all volumes.
     *
     * @return VolumeFolder[]
     */
    private function getAllRootFolders(Assets $assetsService): array {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        return array_filter(
            array_map(
                fn ($vol) => $assetsService->getRootFolderByVolumeId($vol->id),
                $volumes,
            ),
        );
    }

    /**
     * Serialize an asset to array.
     */
    private function serializeAsset(Asset $asset, bool $detailed = false): array {
        $data = [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'kind' => $asset->kind,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $asset->getUrl(),
            'volumeId' => $asset->volumeId,
            'folderId' => $asset->folderId,
            'dateCreated' => $asset->dateCreated?->format('Y-m-d H:i:s'),
            'dateModified' => $asset->dateModified?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['mimeType'] = $asset->mimeType;
            $data['extension'] = $asset->extension;
            $data['folderPath'] = $asset->folderPath;
            $data['alt'] = $asset->alt;

            // Custom fields
            $fieldValues = [];
            if ($asset->getFieldLayout()) {
                foreach ($asset->getFieldLayout()->getCustomFields() as $field) {
                    $value = $asset->getFieldValue($field->handle);
                    $fieldValues[$field->handle] = Serializer::serialize($value);
                }
            }
            $data['fields'] = $fieldValues;

            // Image-specific
            if ($asset->kind === 'image') {
                $data['focalPoint'] = $asset->focalPoint;
            }
        }

        return $data;
    }
}
