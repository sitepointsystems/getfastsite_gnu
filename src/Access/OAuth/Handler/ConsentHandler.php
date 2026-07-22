<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Http\ApiResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /oauth/authorize — processes the consent decision. The authorize
 * params are TokenCipher-encrypted and bound to the user id, so a forged
 * cross-site POST (CSRF) can't produce valid params, and the params must
 * match the current session's user.
 */
final class ConsentHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly SessionService $sessions,
        private readonly TokenCipher $cipher,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->sessions->resolve(SessionService::sessionIdFromCookies($request->getCookieParams(), SessionService::isHttps($request)));
        if ($user === null) {
            return ApiResponse::error('Not signed in.', 401);
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $decoded = $this->cipher->decryptArray((string) ($body['authorize_params'] ?? ''));
        if ($decoded === null || !isset($decoded['params'], $decoded['uid'])) {
            return ApiResponse::error('Invalid authorization request.', 400);
        }
        // The signed params are bound to a user — must match the session.
        if ((int) $decoded['uid'] !== $user['id']) {
            return ApiResponse::error('Authorization request does not match the signed-in user.', 403);
        }

        /** @var array<string, mixed> $params */
        $params = $decoded['params'];
        $clientId = (string) ($params['client_id'] ?? '');
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $state = (string) ($params['state'] ?? '');

        if (!$this->oauth->redirectAllowed($clientId, $redirectUri)) {
            return ApiResponse::error('Invalid client or redirect.', 400);
        }

        if ((string) ($body['decision'] ?? '') !== 'approve') {
            return $this->redirect($redirectUri, ['error' => 'access_denied'] + ($state !== '' ? ['state' => $state] : []));
        }

        /** @var list<string> $scopes */
        $scopes = array_values(array_intersect(explode(' ', (string) ($params['scope'] ?? '')), OAuthService::supportedScopes()));
        $this->oauth->recordConsent($user['id'], $clientId, OAuthService::scopeSetHash($scopes));

        $code = $this->oauth->issueCode($clientId, $user['id'], $scopes, (string) ($params['code_challenge'] ?? ''), $redirectUri);

        return $this->redirect($redirectUri, ['code' => $code] + ($state !== '' ? ['state' => $state] : []));
    }

    /**
     * @param array<string, string> $query
     */
    private function redirect(string $redirectUri, array $query): ResponseInterface
    {
        return new Response(302, ['Location' => $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query)]);
    }
}
