<?php

declare(strict_types=1);

namespace Fastsite\Kernel;

use DI\ContainerBuilder;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Fastsite\Shared\Http\ApiResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

use function FastRoute\simpleDispatcher;

/**
 * Boots the container and dispatches HTTP requests. CLI commands boot the
 * same way and pull services from container().
 */
final class Application
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly ContainerInterface $container,
        private readonly array $config,
    ) {
    }

    public static function boot(?string $rootPath = null): self
    {
        $rootPath ??= \dirname(__DIR__, 2);

        if (is_file($rootPath . '/.env')) {
            \Dotenv\Dotenv::createImmutable($rootPath)->load();
        }

        /** @var array<string, mixed> $config */
        $config = require $rootPath . '/config/app.php';

        $builder = new ContainerBuilder();
        $define = require $rootPath . '/config/services.php';
        $define($builder, $config);

        return new self($builder->build(), $config);
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function handleHttp(): void
    {
        $request = ServerRequest::fromGlobals();

        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)->error('Unhandled exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $message = $this->config['app.env'] === 'local' ? $e->getMessage() : 'Internal server error';
            $response = ApiResponse::error($message, 500);
        }

        $this->emit($response);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        // Direct-serve sites are matched by Host BEFORE routing: on a site's
        // own domain the engine surface (/login, /api, /mcp, dashboards) must
        // not exist — the request is a site file, a redirect, or the site 404.
        $direct = $this->container->get(\Fastsite\Serve\DirectServeGate::class)->intercept($request);
        if ($direct !== null) {
            return $direct;
        }

        /** @var list<Route> $routes */
        $routes = require $this->config['app.root'] . '/config/routes.php';
        /** @var array<string, list<class-string>> $groups */
        $groups = require $this->config['app.root'] . '/config/middleware.php';

        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes): void {
            foreach ($routes as $route) {
                $r->addRoute($route->method, $route->path, $route);
            }
        });

        $result = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        // CORS preflight: a route exists for the path but not for OPTIONS, so
        // answer it here (before the 405) with permissive preflight headers so
        // browser MCP/OAuth clients can call the discovery + token endpoints.
        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED && strtoupper($request->getMethod()) === 'OPTIONS') {
            return new \GuzzleHttp\Psr7\Response(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, DELETE, PUT, PATCH, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version, MCP-Session-Id, X-API-Key',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ]);
        }

        return match ($result[0]) {
            Dispatcher::NOT_FOUND => ApiResponse::error('Not found', 404),
            Dispatcher::METHOD_NOT_ALLOWED => ApiResponse::error('Method not allowed', 405),
            Dispatcher::FOUND => $this->invoke($result[1], $result[2], $request, $groups),
            default => ApiResponse::error('Routing failure', 500),
        };
    }

    /**
     * @param array<string, string> $vars
     * @param array<string, list<class-string>> $groups
     */
    private function invoke(Route $route, array $vars, ServerRequestInterface $request, array $groups): ResponseInterface
    {
        foreach ($vars as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $request = $request->withAttribute(Route::REQUEST_ATTRIBUTE, $route);

        /** @var RequestHandlerInterface $handler */
        $handler = $this->container->get($route->handler);

        $middleware = [];
        foreach ($groups[$route->middlewareGroup] ?? [] as $class) {
            $middleware[] = $this->container->get($class);
        }

        return (new MiddlewarePipeline($middleware, $handler))->handle($request);
    }

    private function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header($name . ': ' . $value, false);
                }
            }
        }

        // Chunked read instead of a full string cast so a file-backed body
        // (direct-served media can be a multi-hundred-MB video) streams out
        // without materializing in PHP memory. Identical output for the
        // string-backed responses everything else returns.
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(65536);
        }
    }
}
