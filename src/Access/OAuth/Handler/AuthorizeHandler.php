<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Access\OAuth\ScopeCatalog;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Http\ApiResponse;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /oauth/authorize — authorization endpoint (OAuth 2.1, S256 PKCE).
 * Validates the request, then: not logged in → login page; logged in but
 * no prior consent → consent page; already consented → issue code + redirect.
 */
final class AuthorizeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly SessionService $sessions,
        private readonly EngineView $view,
        private readonly TokenCipher $cipher,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();

        // Validate the client + redirect BEFORE anything else — errors here
        // cannot be redirected (we'd be redirecting to an unvetted URI).
        $clientId = (string) ($q['client_id'] ?? '');
        $redirectUri = (string) ($q['redirect_uri'] ?? '');
        $client = $this->oauth->getClient($clientId);
        if ($client === null || !$this->oauth->redirectAllowed($clientId, $redirectUri)) {
            return ApiResponse::error('Invalid client_id or redirect_uri.', 400);
        }

        $state = (string) ($q['state'] ?? '');

        // Remaining validation errors redirect back with an error code.
        if ((string) ($q['response_type'] ?? '') !== 'code') {
            return $this->redirectError($redirectUri, 'unsupported_response_type', $state);
        }
        if ((string) ($q['code_challenge_method'] ?? '') !== 'S256' || (string) ($q['code_challenge'] ?? '') === '') {
            return $this->redirectError($redirectUri, 'invalid_request', $state, 'S256 PKCE is required.');
        }

        $scopes = $this->filterScopes((string) ($q['scope'] ?? ''));
        if ($scopes === []) {
            return $this->redirectError($redirectUri, 'invalid_scope', $state);
        }

        // Authenticated?
        $user = $this->sessions->resolve(SessionService::sessionIdFromCookies($request->getCookieParams(), SessionService::isHttps($request)));

        $authorizeParams = $q;
        $authorizeParams['scope'] = implode(' ', $scopes);

        if ($user === null) {
            // Show login; carry the full authorize URL to return to.
            $returnTo = '/oauth/authorize?' . http_build_query($authorizeParams);

            return ApiResponse::html($this->view->render('oauth-login.html.twig', [
                'title' => 'Sign in — fastsite',
                'client_name' => $client['client_name'] !== '' ? $client['client_name'] : $clientId,
                'return_to' => $returnTo,
            ]));
        }

        $scopeSetHash = OAuthService::scopeSetHash($scopes);
        if ($this->oauth->hasConsent($user['id'], $clientId, $scopeSetHash)) {
            return $this->issueAndRedirect($clientId, $user['id'], $scopes, (string) $q['code_challenge'], $redirectUri, $state);
        }

        // Show consent. The params are signed so the POST can't be tampered.
        $signed = (string) $this->cipher->encryptArray(['params' => $authorizeParams, 'uid' => $user['id']]);

        return ApiResponse::html($this->view->render('oauth-consent.html.twig', [
            'title' => 'Authorize — fastsite',
            'client_name' => $client['client_name'] !== '' ? $client['client_name'] : $clientId,
            'user_email' => $user['email'],
            'scope_descriptions' => ScopeCatalog::describe($scopes),
            'authorize_params' => $signed,
        ]));
    }

    private function issueAndRedirect(string $clientId, int $userId, array $scopes, string $challenge, string $redirectUri, string $state): ResponseInterface
    {
        $code = $this->oauth->issueCode($clientId, $userId, $scopes, $challenge, $redirectUri);
        $query = ['code' => $code];
        if ($state !== '') {
            $query['state'] = $state;
        }

        return new Response(302, ['Location' => $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query)]);
    }

    private function redirectError(string $redirectUri, string $error, string $state, string $description = ''): ResponseInterface
    {
        $query = ['error' => $error];
        if ($description !== '') {
            $query['error_description'] = $description;
        }
        if ($state !== '') {
            $query['state'] = $state;
        }

        return new Response(302, ['Location' => $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query)]);
    }

    /**
     * @return list<string>
     */
    private function filterScopes(string $scope): array
    {
        $requested = array_filter(explode(' ', trim($scope)));
        $supported = OAuthService::supportedScopes();

        return array_values(array_intersect($requested, $supported));
    }
}
