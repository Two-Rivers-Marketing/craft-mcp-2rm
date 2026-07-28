<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use GuzzleHttp\Psr7\ServerRequest as PsrServerRequest;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use twoRivers\craft\Mcp\Mcp;
use twoRivers\craft\Mcp\services\McpServerFactory;
use twoRivers\craft\Mcp\tools\TinkerTools;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * HTTP transport for the MCP server.
 *
 * A dev-only convenience: it lets the MCP client connect over HTTP
 * (`https://<site>/mcp`) so that editing plugin code takes effect on the next
 * request — no stdio-server restart, no client reconnect. Because the endpoint
 * can run arbitrary PHP (tinker), it is gated three ways: the route only
 * registers under devMode + the `httpTransport` setting (see Mcp::init()), and
 * every request must carry the `MCP_HTTP_TOKEN` bearer token checked here.
 *
 * @author 2RM
 */
class McpController extends Controller {
    protected array|bool|int $allowAnonymous = true;

    /**
     * Handle a single JSON-RPC message over Streamable HTTP.
     *
     * Each call is an ordinary Craft web request: build a PSR-7 request, drive
     * the request-scoped transport once, and emit its PSR-7 response.
     */
    public function actionHandle(): Response {
        $this->assertAuthorized();

        $logger = McpServerFactory::createFileLogger(logLevel: Mcp::settings()->logLevel);

        // Mirror bin/mcp-server's DI wiring so tools receive the file logger
        // (and TinkerTools resolves through the container, not bare-instantiated).
        Craft::$container->setSingleton(LoggerInterface::class, fn (): LoggerInterface => $logger);
        Craft::$container->set(TinkerTools::class);

        $factory = new McpServerFactory(logger: $logger);
        $server = $factory->create(persistentSessions: true);
        $transport = $factory->createHttpTransport(
            $this->buildPsrRequest(),
            [Craft::$app->getRequest()->getHostName() ?? 'localhost'],
        );

        return $this->emit($server->run($transport));
    }

    /**
     * @throws ForbiddenHttpException when the bearer token is missing or wrong
     */
    private function assertAuthorized(): void {
        // CORS preflight carries no auth header; let the transport answer it.
        if (Craft::$app->getRequest()->getMethod() === 'OPTIONS') {
            return;
        }

        if (!Mcp::settings()->httpTransport) {
            throw new ForbiddenHttpException('MCP HTTP transport is disabled.');
        }

        $token = App::env('MCP_HTTP_TOKEN');

        if (!is_string($token) || $token === '') {
            throw new ForbiddenHttpException('MCP HTTP transport requires MCP_HTTP_TOKEN to be set.');
        }

        $header = Craft::$app->getRequest()->getHeaders()->get('Authorization', '');
        $provided = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if ($provided === '' || !hash_equals($token, $provided)) {
            throw new ForbiddenHttpException('Invalid or missing MCP bearer token.');
        }
    }

    /**
     * Build a PSR-7 request from the current Craft request, reading the body
     * via getRawBody() to avoid touching php://input twice.
     */
    private function buildPsrRequest(): PsrServerRequest {
        $request = Craft::$app->getRequest();

        // streamFor() leaves the pointer at the end; rewind so the transport's
        // getBody()->getContents() actually reads the JSON-RPC payload.
        $body = Utils::streamFor($request->getRawBody());
        $body->rewind();

        return new PsrServerRequest(
            method: $request->getMethod(),
            uri: $request->getAbsoluteUrl(),
            headers: $request->getHeaders()->toArray(),
            body: $body,
        );
    }

    /**
     * Copy a PSR-7 response onto Craft's response object.
     */
    private function emit(\Psr\Http\Message\ResponseInterface $psrResponse): Response {
        $response = Craft::$app->getResponse();
        $response->setStatusCode($psrResponse->getStatusCode());
        $response->format = Response::FORMAT_RAW;

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->getHeaders()->set($name, implode(', ', $values));
        }

        $response->content = (string) $psrResponse->getBody();

        return $response;
    }

    public function beforeAction($action): bool {
        $this->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }
}
