<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dynamic client registration (RFC 7591). Public clients only.
 */
final class RegisterHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return ApiResponse::json(['error' => 'invalid_client_metadata', 'error_description' => 'Body must be JSON.'], 400);
        }

        $redirectUris = $body['redirect_uris'] ?? [];
        if (!\is_array($redirectUris)) {
            return ApiResponse::json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must be an array.'], 400);
        }

        try {
            $client = $this->oauth->registerClient(
                (string) ($body['client_name'] ?? 'MCP client'),
                array_values(array_map('strval', $redirectUris)),
                (string) ($body['logo_uri'] ?? ''),
                (string) ($body['client_uri'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::json(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()], 400);
        }

        return ApiResponse::json([
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uris' => $client['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], 201);
    }
}
