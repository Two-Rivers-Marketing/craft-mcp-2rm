<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\services;

use Craft;
use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use twoRivers\craft\Mcp\Mcp;
use twoRivers\craft\Mcp\models\ResourceDefinition;
use twoRivers\craft\Mcp\support\FileLogger;
use twoRivers\craft\Mcp\support\Psr11ContainerAdapter;

/**
 * Factory for creating MCP Server instances.
 *
 * Follows DIP: depends on abstractions (ContainerInterface, registries via McpRegistry facade).
 * Follows SRP: sole responsibility is building properly configured Server instances.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class McpServerFactory {
    public function __construct(private readonly ?ContainerInterface $container = new Psr11ContainerAdapter(), private readonly ?LoggerInterface $logger = null) {
    }

    /**
     * Create a configured MCP Server instance.
     *
     * @param bool $persistentSessions Persist MCP sessions to disk instead of
     *                                 in-memory. Required for the HTTP transport,
     *                                 where each request is a fresh process and
     *                                 an in-memory store would forget the session
     *                                 established by `initialize`.
     */
    public function create(bool $persistentSessions = false): Server {
        $builder = Server::builder()
            ->setServerInfo(
                name: 'Craft CMS MCP Server',
                version: Mcp::getInstance()?->getVersion() ?? '1.0.0',
            )
            ->setInstructions($this->getInstructions())
            ->setDiscovery(
                basePath: dirname(__DIR__),
                scanDirs: ['tools', 'prompts', 'resources'],
                excludeDirs: ['vendor', 'support', 'services', 'events', 'models', 'enums', 'attributes', 'completions', 'contracts'],
            )
            ->setContainer($this->container);

        if ($persistentSessions) {
            $builder->setSession(new FileSessionStore(Craft::getAlias('@storage/mcp-sessions')));
        }

        // Add custom logger if provided (writes to separate file, not Craft logs)
        if ($this->logger !== null) {
            $builder->setLogger($this->logger);
        }

        $this->registerExternalElements($builder);

        return $builder->build();
    }

    /**
     * Create a StdioTransport for the server.
     */
    public function createTransport(): StdioTransport {
        return new StdioTransport();
    }

    /**
     * Create a request-scoped StreamableHttpTransport from an incoming PSR-7
     * request. One request in, one response out — the transport is driven by
     * the Craft controller, not a socket loop.
     *
     * The default DNS-rebinding guard only allows localhost hosts, so the
     * serving site's own hostname is added to the allow-list (the bearer token
     * + devMode gate remain the real access control).
     *
     * @param list<string> $allowedHosts Hostnames (no port) permitted by the DNS-rebinding guard.
     */
    public function createHttpTransport(ServerRequestInterface $request, array $allowedHosts): StreamableHttpTransport {
        $middleware = [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware([...$allowedHosts, 'localhost', '127.0.0.1', '[::1]']),
            new ProtocolVersionMiddleware(),
        ];

        return new StreamableHttpTransport(
            request: $request,
            responseFactory: Psr17FactoryDiscovery::findResponseFactory(),
            streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
            logger: $this->logger,
            middleware: $middleware,
        );
    }

    /**
     * Create a file logger that writes to storage/logs/mcp-server.log.
     * This is separate from Craft's logging system.
     */
    public static function createFileLogger(?string $logPath = null, string $logLevel = 'error'): LoggerInterface {
        if ($logPath === null) {
            $logPath = Craft::getAlias('@storage/logs/mcp-server.log');
        }

        return new FileLogger($logPath, $logLevel);
    }

    private function getInstructions(): string {
        return <<<'INSTRUCTIONS'
This MCP server provides access to a Craft CMS installation built on the 2RM content model.

## The 2RM Content Model

Pages on 2RM sites are entries whose primary content lives in a Neo field called the
"content builder" (default handle `contentBuilder`, configurable via the plugin's
`builderFieldHandle` setting). Everything the visitor sees is a tree of Neo blocks:

- **Block types map 1:1 to Twig templates** at `templates/body_blocks/<blockTypeHandle>.twig`.
  A block type without a matching template renders nothing.
- **Shared property fields** appear on most block types and control presentation:
  `sectionProperties` (layout/spacing), `backgroundProperties` (background color/image),
  and `extraClasses` (extra CSS classes). Treat these as styling, not content.
- **Nesting rules** (topLevel, childBlocks, maxChildBlocks) define which blocks can
  contain which - e.g. column containers holding column items.

## Orientation Workflow

1. Call `describe_content_builder` FIRST before any content-builder work. It returns
   every block type with its fields (including valid option values for dropdowns,
   radio buttons, checkboxes, multi-selects, and lightswitches), nesting rules, and
   whether the matching body_blocks template exists.
2. Use `get_block_type` to inspect a single block type in full depth.
3. Use `list_*` tools to explore available data before making changes.
4. Use `get_*` tools to inspect specific items.
5. Check schema/fields before creating or updating entries; read before you mutate.

## Available Capabilities

**Tools**: Query and manage entries, assets, users, categories, commerce data, Neo schema
**Resources**: Read configuration, schema information, system state
**Prompts**: Generate content, analyze structure, create entries
INSTRUCTIONS;
    }

    private function registerExternalElements(Builder $builder): void {
        $this->registerExternalTools($builder);
        $this->registerExternalPrompts($builder);
        $this->registerExternalResources($builder);
    }

    private function registerExternalTools(Builder $builder): void {
        foreach (McpRegistry::tools()->getExternalToolDefinitions() as $def) {
            $builder->addTool(
                handler: [$def->class, $def->method],
                name: $def->name,
                description: $def->description,
            );
        }
    }

    private function registerExternalPrompts(Builder $builder): void {
        foreach (McpRegistry::prompts()->getExternalPromptDefinitions() as $def) {
            $builder->addPrompt(
                handler: [$def->class, $def->method],
                name: $def->name,
                description: $def->description,
            );
        }
    }

    private function registerExternalResources(Builder $builder): void {
        foreach (McpRegistry::resources()->getExternalResourceDefinitions() as $def) {
            $this->registerResource($builder, $def);
        }
    }

    private function registerResource(Builder $builder, ResourceDefinition $def): void {
        if ($def->isTemplate) {
            $builder->addResourceTemplate(
                handler: [$def->class, $def->method],
                uriTemplate: $def->uri,
                name: $def->name,
                description: $def->description,
                mimeType: $def->mimeType,
            );

            return;
        }

        $builder->addResource(
            handler: [$def->class, $def->method],
            uri: $def->uri,
            name: $def->name,
            description: $def->description,
            mimeType: $def->mimeType,
        );
    }
}
