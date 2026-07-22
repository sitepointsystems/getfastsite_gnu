<?php

declare(strict_types=1);

namespace Fastsite\Access\Middleware;

use Fastsite\Access\AccessContext;
use Fastsite\Kernel\Route;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces the route's declared scope and, when the route carries a {site}
 * variable, the key's site restriction. Runs after ApiKeyAuthMiddleware.
 */
final class ScopeCheckMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $route = $request->getAttribute(Route::REQUEST_ATTRIBUTE);

        if (!$access instanceof AccessContext || !$route instanceof Route) {
            return ApiResponse::error('Access context missing', 500);
        }

        if ($route->scope !== null && !$access->hasScope($route->scope)) {
            return ApiResponse::error('Insufficient scope: ' . $route->scope . ' required', 403);
        }

        $site = $request->getAttribute('site');
        if (\is_string($site) && $site !== '' && !$access->allowsSite($site)) {
            return ApiResponse::error('API key is not allowed to act on site: ' . $site, 403);
        }

        return $handler->handle($request);
    }
}
