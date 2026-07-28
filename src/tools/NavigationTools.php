<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;
use verbb\navigation\elements\Node;
use verbb\navigation\models\Nav;
use verbb\navigation\models\Nav_SiteSettings;
use verbb\navigation\Navigation;
use verbb\navigation\nodetypes\CustomType;

/**
 * Navigation MCP tools for Craft CMS.
 *
 * Only registered if the Navigation plugin (verbb/navigation) is installed.
 * A "nav" is a menu container (project-config backed); a "node" is a single
 * item in the menu, stored as a Structure element tree. Reads discover the
 * menus and their trees; writes go through Craft's element/service APIs,
 * never raw SQL.
 *
 * @author 2RM
 */
class NavigationTools implements ConditionalToolProvider {
    /**
     * Check if the Navigation plugin is available.
     */
    public static function isAvailable(): bool {
        if (!class_exists(Navigation::class)) {
            return false;
        }

        $plugins = Craft::$app->getPlugins();

        if ($plugins->isPluginEnabled('navigation')) {
            return true;
        }

        $config = Craft::$app->getProjectConfig()->get('plugins.navigation');
        $enabledInConfig = $config !== null && ($config['enabled'] ?? false) === true;

        if (!$enabledInConfig) {
            return false;
        }

        $plugins->loadPlugins();

        return $plugins->isPluginEnabled('navigation');
    }

    /**
     * List all navigation menus (shallow — no nodes).
     */
    #[McpTool(
        name: 'list_navs',
        description: 'List all navigation menus (navs) with their id, name, handle, structure id and max levels. Use get_nav to read a menu\'s node tree.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listNavs(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $navs = Navigation::$plugin->getNavs()->getAllNavs();
            $results = array_map($this->serializeNav(...), $navs);

            return Response::list('navs', $results);
        });
    }

    /**
     * Get a single nav plus its full node tree.
     */
    #[McpTool(
        name: 'get_nav',
        description: 'Get one navigation menu by handle and its full node tree. Each node reports id, title, url, type (Custom URL or the linked element class), elementId, newWindow, enabled, classes, level and children.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getNav(string $handle, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($handle): array {
            $nav = Navigation::$plugin->getNavs()->getNavByHandle($handle);

            if ($nav === null) {
                throw new ToolCallException("Nav with handle '{$handle}' not found");
            }

            $nodes = Navigation::$plugin->getNodes()->getNodesForNav($nav->id);
            $flat = array_map($this->serializeNode(...), $nodes);

            $data = $this->serializeNav($nav);
            $data['nodes'] = $this->buildTree($flat, null);

            return Response::found('nav', $data);
        });
    }

    /**
     * Create a new navigation menu.
     */
    #[McpTool(
        name: 'create_nav',
        description: 'Create a new (empty) navigation menu. name is the display name, handle its machine handle (must be unique). maxLevels optionally caps nesting depth (omit for unlimited). Enabled on every site by default. Writes project config, so the site must allow admin changes (ALLOW_ADMIN_CHANGES / allowAdminChanges).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createNav(
        string $name,
        string $handle,
        ?int $maxLevels = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($name, $handle, $maxLevels): array {
            if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                throw new ToolCallException('Cannot create a nav: admin changes are disabled (set ALLOW_ADMIN_CHANGES=true). Navs are stored in project config.');
            }

            if (Navigation::$plugin->getNavs()->getNavByHandle($handle) !== null) {
                throw new ToolCallException("A nav with handle '{$handle}' already exists");
            }

            $nav = new Nav();
            $nav->name = $name;
            $nav->handle = $handle;
            $nav->maxLevels = $maxLevels;
            $nav->setSiteSettings($this->allSiteSettings());

            if (!Navigation::$plugin->getNavs()->saveNav($nav)) {
                throw new ToolCallException('Failed to save nav: ' . json_encode($nav->getErrors()));
            }

            return Response::success(['nav' => $this->serializeNav($nav)]);
        });
    }

    /**
     * Add a node to a nav.
     */
    #[McpTool(
        name: 'create_node',
        description: 'Add a node (menu item) to a nav, identified by nav handle. title is the link label. Provide EITHER url (a custom/manual URL) OR elementId (link to an existing entry/category/asset — the node tracks that element\'s live URL). newWindow opens the link in a new tab. parentId optionally nests this node under an existing node in the same nav. classes/urlSuffix are optional extras.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createNode(
        string $navHandle,
        string $title,
        ?string $url = null,
        ?int $elementId = null,
        bool $newWindow = false,
        ?int $parentId = null,
        ?string $classes = null,
        ?string $urlSuffix = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use (
            $navHandle,
            $title,
            $url,
            $elementId,
            $newWindow,
            $parentId,
            $classes,
            $urlSuffix,
        ): array {
            $nav = Navigation::$plugin->getNavs()->getNavByHandle($navHandle);

            if ($nav === null) {
                throw new ToolCallException("Nav with handle '{$navHandle}' not found");
            }

            $node = new Node();
            $node->navId = $nav->id;
            $node->siteId = Craft::$app->getSites()->getPrimarySite()->id;
            $node->title = $title;
            $node->enabled = true;
            $node->newWindow = $newWindow;
            $node->classes = $classes;
            $node->urlSuffix = $urlSuffix;

            $this->applyTarget($node, $url, $elementId);

            if ($parentId !== null) {
                $node->setParentId($parentId);
            }

            if (!Craft::$app->getElements()->saveElement($node, true)) {
                throw new ToolCallException('Failed to save node: ' . json_encode($node->getErrors()));
            }

            return Response::success(['node' => $this->serializeNode($node)]);
        });
    }

    /**
     * Update an existing node.
     */
    #[McpTool(
        name: 'update_node',
        description: 'Update an existing node by id. Any of title, url, newWindow, classes, urlSuffix, enabled may be set; omitted values are left untouched. To re-target a node between custom-URL and element-link, or to move it in the tree, delete and recreate it.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateNode(
        int $id,
        ?string $title = null,
        ?string $url = null,
        ?bool $newWindow = null,
        ?string $classes = null,
        ?string $urlSuffix = null,
        ?bool $enabled = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $title, $url, $newWindow, $classes, $urlSuffix, $enabled): array {
            $node = Navigation::$plugin->getNodes()->getNodeById($id);

            if ($node === null) {
                throw new ToolCallException("Node with id {$id} not found");
            }

            $this->applyIfSet($node, 'title', $title);
            $this->applyIfSet($node, 'url', $url);
            $this->applyIfSet($node, 'newWindow', $newWindow);
            $this->applyIfSet($node, 'classes', $classes);
            $this->applyIfSet($node, 'urlSuffix', $urlSuffix);
            $this->applyIfSet($node, 'enabled', $enabled);

            if (!Craft::$app->getElements()->saveElement($node, true)) {
                throw new ToolCallException('Failed to save node: ' . json_encode($node->getErrors()));
            }

            return Response::success(['node' => $this->serializeNode($node)]);
        });
    }

    /**
     * Delete a node (and its descendants, per Craft structure cascade).
     */
    #[McpTool(
        name: 'delete_node',
        description: 'Delete a node by id. Deleting a node also removes its descendant nodes (Craft structure cascade). Irreversible.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function deleteNode(int $id, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id): array {
            $node = Navigation::$plugin->getNodes()->getNodeById($id);

            if ($node === null) {
                throw new ToolCallException("Node with id {$id} not found");
            }

            if (!Craft::$app->getElements()->deleteElement($node)) {
                throw new ToolCallException("Failed to delete node {$id}");
            }

            return Response::success(['deletedNodeId' => $id]);
        });
    }

    /**
     * Point a node at a custom URL or an existing element.
     */
    private function applyTarget(Node $node, ?string $url, ?int $elementId): void {
        if ($elementId === null) {
            $node->type = CustomType::class;
            $node->url = $url;

            return;
        }

        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            throw new ToolCallException("elementId {$elementId} does not resolve to an element");
        }

        $node->type = $element::class;
        $node->elementId = $elementId;
        $node->setElement($element);
    }

    /**
     * Set a node property only when a value was provided.
     */
    private function applyIfSet(Node $node, string $property, mixed $value): void {
        if ($value === null) {
            return;
        }

        $node->{$property} = $value;
    }

    /**
     * Build Nav_SiteSettings (enabled) for every site.
     *
     * @return array<int, Nav_SiteSettings>
     */
    private function allSiteSettings(): array {
        return array_map(function ($site): Nav_SiteSettings {
            $settings = new Nav_SiteSettings();
            $settings->siteId = $site->id;
            $settings->enabled = true;

            return $settings;
        }, Craft::$app->getSites()->getAllSites());
    }

    /**
     * Nest a flat, ordered list of serialized nodes by parentId.
     *
     * @param  array<int, array<string, mixed>>  $flat
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $flat, ?int $parentId): array {
        $children = [];

        foreach ($flat as $node) {
            if (($node['parentId'] ?? null) !== $parentId) {
                continue;
            }

            $node['children'] = $this->buildTree($flat, $node['id']);
            $children[] = $node;
        }

        return $children;
    }

    /**
     * Serialize a nav model to array.
     */
    private function serializeNav(mixed $nav): array {
        return [
            'id' => $nav->id,
            'name' => $nav->name,
            'handle' => $nav->handle,
            'structureId' => $nav->structureId,
            'maxLevels' => $nav->maxLevels,
        ];
    }

    /**
     * Serialize a node element to array.
     */
    private function serializeNode(mixed $node): array {
        return [
            'id' => $node->id,
            'title' => $node->title,
            'url' => $node->getUrl(),
            'type' => $node->type,
            'elementId' => $node->elementId,
            'newWindow' => $node->newWindow,
            'enabled' => (bool) $node->enabled,
            'classes' => $node->classes,
            'urlSuffix' => $node->urlSuffix,
            'level' => $node->level,
            'parentId' => $node->getParentId(),
        ];
    }
}
