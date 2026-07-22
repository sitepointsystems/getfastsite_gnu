<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

use Fastsite\Access\AccessContext;
use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Http\PublicUrl;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MCP bearer auth. A missing/invalid token returns 401 with a
 * WWW-Authenticate header pointing at the protected-resource metadata, so an
 * MCP client (Claude Desktop) can discover the authorization server and run
 * the OAuth flow.
 */
final class McpAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly string $appUrl,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) {
            return $this->challenge($request, 'missing token');
        }

        $access = $this->oauth->verifyAccessToken(substr($auth, 7));
        if ($access === null) {
            return $this->challenge($request, 'invalid or expired token');
        }

        return $handler->handle($request->withAttribute(AccessContext::REQUEST_ATTRIBUTE, $access));
    }

    private function challenge(ServerRequestInterface $request, string $reason): ResponseInterface
    {
        // Point the client at the reachable public origin, not a stale APP_URL.
        $metadata = PublicUrl::base($request, $this->appUrl) . '/.well-known/oauth-protected-resource';

        return ApiResponse::json(['error' => 'unauthorized', 'error_description' => $reason], 401)
            ->withHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $metadata));
    }
}
