<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Token revocation (RFC 7009). Always 200, even for an unknown token.
 */
final class RevokeHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        if (!\is_array($parsed) || $parsed === []) {
            parse_str((string) $request->getBody(), $parsed);
        }
        $token = (string) ($parsed['token'] ?? '');
        if ($token !== '') {
            $this->oauth->revoke($token);
        }

        return new Response(200);
    }
}
