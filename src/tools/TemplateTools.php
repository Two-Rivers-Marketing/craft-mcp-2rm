<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\web\View;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use Twig\Error\Error as TwigError;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Template rendering MCP tools for Craft CMS.
 *
 * Lets an assistant confirm that a content change actually renders, rather
 * than only confirming that the data saved.
 *
 * @author 2RM
 */
class TemplateTools {
    /**
     * Render a site template and return its HTML.
     */
    #[McpTool(
        name: 'render_template',
        description: 'Render a Twig template in site template mode and return the HTML, to verify that content actually renders. template is a site template path (e.g. "body_blocks/entryCard.twig"); templates served by a plugin template root must be prefixed with the root key (e.g. "site-toolkit/body_blocks/entryCard.twig"). variables is a JSON object of template variables. Output beyond maxLength is truncated and flagged with truncated: true, with the full byte length reported as length. Executes Twig with the supplied variables.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function renderTemplate(
        string $template,
        ?string $variables = null,
        int $maxLength = 32768,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($template, $variables, $maxLength): array {
            if ($maxLength < 1) {
                throw new ToolCallException("maxLength must be at least 1, got {$maxLength}");
            }

            /** @var View $view */
            $view = Craft::$app->getView();

            // Both resolveTemplate() and renderTemplate() set the template mode and
            // restore the previous one in a finally block, so passing the mode is
            // enough — no manual save/restore needed here.
            $resolvedPath = $view->resolveTemplate($template, View::TEMPLATE_MODE_SITE);

            if ($resolvedPath === false) {
                throw new ToolCallException(sprintf(
                    'Template "%s" did not resolve in site template mode (searched from %s). Templates served by a plugin template root must be prefixed with the root key, e.g. "site-toolkit/%s".',
                    $template,
                    Craft::$app->getPath()->getSiteTemplatesPath(),
                    $template,
                ));
            }

            $html = $this->render($view, $template, $this->decodeVariables($variables));
            $length = strlen($html);
            $truncated = $length > $maxLength;

            return Response::success([
                'template' => $template,
                'resolvedPath' => $resolvedPath,
                'length' => $length,
                'truncated' => $truncated,
                'html' => $truncated ? substr($html, 0, $maxLength) : $html,
            ]);
        });
    }

    /**
     * Render the template, surfacing Twig errors as tool errors.
     *
     * @param array<string, mixed> $variables
     */
    private function render(View $view, string $template, array $variables): string {
        try {
            return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        } catch (TwigError $e) {
            throw new ToolCallException(
                "Twig error rendering \"{$template}\": " . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Decode the JSON variables parameter into a Twig variable array.
     *
     * @return array<string, mixed>
     */
    private function decodeVariables(?string $variables): array {
        if ($variables === null || trim($variables) === '') {
            return [];
        }

        $decoded = json_decode($variables, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new ToolCallException('Invalid JSON in variables parameter (expected a JSON object of variableName => value)');
        }

        return $decoded;
    }
}
