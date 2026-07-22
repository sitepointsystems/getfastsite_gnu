<?php

declare(strict_types=1);

namespace Fastsite\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Permissive CORS for the discovery/OAuth/MCP surface so browser-based MCP
 * clients (claude.ai, the MCP Inspector) can fetch metadata, register, and
 * exchange tokens cross-origin. These endpoints are authenticated by bearer
 * token / PKCE, not by cookies, so `*` is safe (no credentialed CORS). The
 * WWW-Authenticate header is exposed so a client can read the 401 challenge.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Expose-Headers', 'WWW-Authenticate')
            ->withHeader('Vary', 'Origin');
    }
}
