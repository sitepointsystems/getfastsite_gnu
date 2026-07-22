<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Http\PublicUrl;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OAuth 2.1 authorization-server metadata (RFC 8414) and protected-resource
 * metadata (RFC 9728). One handler; the route path selects which document.
 * Absolute URLs use the request-derived public origin (see PublicUrl) so a
 * remote MCP client always gets reachable, CORS-accessible discovery.
 */
final class MetadataHandler implements RequestHandlerInterface
{
    public function __construct(private readonly string $appUrl)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $base = PublicUrl::base($request, $this->appUrl);

        if (str_contains($request->getUri()->getPath(), 'oauth-protected-resource')) {
            return self::cors(ApiResponse::json([
                'resource' => $base . '/mcp',
                'authorization_servers' => [$base],
                'scopes_supported' => OAuthService::supportedScopes(),
                'bearer_methods_supported' => ['header'],
            ]));
        }

        return self::cors(ApiResponse::json([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'scopes_supported' => OAuthService::supportedScopes(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
        ]));
    }

    /** Discovery metadata must be fetchable cross-origin by browser clients. */
    private static function cors(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
