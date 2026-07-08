<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use craft\elements\Category;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Category MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class CategoryTools {
    /**
     * List categories.
     */
    #[McpTool(
        name: 'list_categories',
        description: 'List categories from Craft CMS. Filter by group handle.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listCategories(?string $group = null, int $limit = 100, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($group, $limit): array {
            $query = Category::find()->limit($limit);

            if ($group !== null) {
                $query->group($group);
            }

            $categories = $query->all();
            $results = array_map($this->serializeCategory(...), $categories);

            return Response::list('categories', $results);
        });
    }

    /**
     * Serialize a category to array.
     */
    private function serializeCategory(Category $category): array {
        return [
            'id' => $category->id,
            'title' => $category->title,
            'slug' => $category->slug,
            'level' => $category->level,
            'groupId' => $category->groupId,
            'groupHandle' => $category->getGroup()?->handle, // @phpstan-ignore nullsafe.neverNull
            'url' => $category->getUrl(),
        ];
    }
}
