<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthException;
use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OAuth token endpoint: authorization_code (with PKCE) and refresh_token
 * (with rotation). Public clients — no client secret.
 */
final class TokenHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $this->formParams($request);
        $grantType = (string) ($params['grant_type'] ?? '');
        $clientId = (string) ($params['client_id'] ?? '');

        try {
            $tokens = match ($grantType) {
                'authorization_code' => $this->oauth->exchangeCode(
                    (string) ($params['code'] ?? ''),
                    (string) ($params['code_verifier'] ?? ''),
                    $clientId,
                    (string) ($params['redirect_uri'] ?? ''),
                ),
                'refresh_token' => $this->oauth->refresh((string) ($params['refresh_token'] ?? ''), $clientId),
                default => throw new OAuthException('unsupported_grant_type', 'Unsupported grant_type.'),
            };
        } catch (OAuthException $e) {
            return ApiResponse::json(['error' => $e->errorCode, 'error_description' => $e->getMessage()], 400)
                ->withHeader('Cache-Control', 'no-store');
        }

        return ApiResponse::json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'refresh_token' => $tokens['refresh_token'],
            'scope' => $tokens['scope'],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function formParams(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (\is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        parse_str((string) $request->getBody(), $params);

        return $params;
    }
}
