<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use twoRivers\craft\Mcp\Mcp;

/**
 * Discovers the plugin's tools, prompts and resources and registers only the
 * ones the site's settings allow.
 *
 * The SDK's own discovery registers every attribute it finds, so
 * `disabledTools`, `enableDangerousTools`, `disabledPrompts`,
 * `disabledResources` and method conditions were reported by `list_mcp_tools`
 * but never applied: a disabled tool was still listed and callable. Deciding
 * here, before registration, keeps a refused element out of the list responses
 * and the call handlers, and out of the capabilities the server announces.
 *
 * An element with no definition in the plugin's own registries is refused: it
 * has passed none of the checks.
 */
final readonly class GatedDiscoveryLoader implements LoaderInterface {
    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     */
    public function __construct(
        private string $basePath,
        private array $scanDirs,
        private array $excludeDirs = [],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function load(RegistryInterface $registry): void {
        $discovered = new Discoverer($this->logger)->discover($this->basePath, $this->scanDirs, $this->excludeDirs);

        $this->loadTools($registry, $discovered);
        $this->loadPrompts($registry, $discovered);
        $this->loadResources($registry, $discovered);
        $this->loadResourceTemplates($registry, $discovered);
    }

    public static function admitsTool(string $name): bool {
        return Mcp::getToolRegistry()->getDefinition($name) !== null && Mcp::isToolEnabled($name);
    }

    public static function admitsPrompt(string $name): bool {
        return Mcp::getPromptRegistry()->getDefinition($name) !== null && Mcp::isPromptEnabled($name);
    }

    public static function admitsResource(string $uri): bool {
        return Mcp::getResourceRegistry()->getDefinition($uri) !== null && Mcp::isResourceEnabled($uri);
    }

    /**
     * Templates are keyed by name in the resource registry. `disabledResources`
     * may name a template by either its name or its URI template.
     */
    public static function admitsResourceTemplate(string $name, string $uriTemplate): bool {
        return Mcp::getResourceRegistry()->getDefinition($name) !== null
            && Mcp::isResourceEnabled($name)
            && !in_array($uriTemplate, Mcp::settings()->disabledResources, true);
    }

    private function loadTools(RegistryInterface $registry, DiscoveryState $discovered): void {
        foreach ($discovered->getTools() as $name => $reference) {
            if (!self::admitsTool($name)) {
                $this->logger->debug("Not registering tool '{$name}': disabled by settings, a condition, or the dangerous-tools gate.");

                continue;
            }

            $registry->registerTool($reference->tool, $reference->handler);
        }
    }

    private function loadPrompts(RegistryInterface $registry, DiscoveryState $discovered): void {
        foreach ($discovered->getPrompts() as $name => $reference) {
            if (!self::admitsPrompt($name)) {
                $this->logger->debug("Not registering prompt '{$name}': disabled by settings or a condition.");

                continue;
            }

            $registry->registerPrompt($reference->prompt, $reference->handler, $reference->completionProviders);
        }
    }

    private function loadResources(RegistryInterface $registry, DiscoveryState $discovered): void {
        foreach ($discovered->getResources() as $uri => $reference) {
            if (!self::admitsResource($uri)) {
                $this->logger->debug("Not registering resource '{$uri}': disabled by settings or a condition.");

                continue;
            }

            $registry->registerResource($reference->resource, $reference->handler);
        }
    }

    private function loadResourceTemplates(RegistryInterface $registry, DiscoveryState $discovered): void {
        foreach ($discovered->getResourceTemplates() as $uriTemplate => $reference) {
            if (!self::admitsResourceTemplate($reference->resourceTemplate->name, $uriTemplate)) {
                $this->logger->debug("Not registering resource template '{$uriTemplate}': disabled by settings or a condition.");

                continue;
            }

            $registry->registerResourceTemplate($reference->resourceTemplate, $reference->handler, $reference->completionProviders);
        }
    }
}
