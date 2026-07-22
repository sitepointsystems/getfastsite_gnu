<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\LoginFlow;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /login — two steps in one endpoint: credentials (email+password) or
 * the TOTP code for a pending challenge. Unauthenticated by design, so no
 * CSRF token (login CSRF is mitigated by SameSite=Lax cookies).
 */
final class LoginActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LoginFlow $flow,
        private readonly RememberTokenService $remember,
        private readonly EngineView $view,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $params = $request->getServerParams();
        $ip = (string) ($params['REMOTE_ADDR'] ?? '');
        $ua = $request->getHeaderLine('User-Agent');
        $secure = SessionService::isHttps($request);
        $next = LoginPageHandler::safeNext((string) ($body['next'] ?? '/'));
        $wantsRemember = (string) ($body['remember'] ?? '') === '1';

        // Step 2: TOTP code for a pending challenge.
        if (isset($body['challenge'])) {
            $session = $this->flow->completeTotp((string) $body['challenge'], (string) ($body['code'] ?? ''), $ip, $ua);
            if ($session === null) {
                return $this->page(401, ['challenge' => (string) $body['challenge'], 'next' => $next, 'remember' => $wantsRemember, 'error' => 'Wrong or expired code. Try again.']);
            }

            return $this->success($session, $next, $wantsRemember, $secure);
        }

        // Step 1: credentials.
        $result = $this->flow->begin((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''), $ip, $ua);

        return match ($result['status']) {
            'bad' => $this->page(401, ['challenge' => '', 'next' => $next, 'remember' => $wantsRemember, 'error' => 'Invalid credentials, or too many attempts. Try again.']),
            'totp' => $this->page(200, ['challenge' => $result['challenge'], 'next' => $next, 'remember' => $wantsRemember, 'error' => '']),
            'ok' => $this->success($result['session'], $next, $wantsRemember, $secure),
        };
    }

    private function success(string $sessionId, string $next, bool $wantsRemember, bool $secure): ResponseInterface
    {
        $response = (new Response(302, ['Location' => $next]))
            ->withAddedHeader('Set-Cookie', SessionService::setCookie($sessionId, $secure));

        if ($wantsRemember) {
            // resolve() only returns for a live session we just minted.
            $userId = $this->flow->userIdForFreshSession($sessionId);
            if ($userId !== null) {
                $response = $response->withAddedHeader(
                    'Set-Cookie',
                    RememberTokenService::setCookie($this->remember->issue($userId), $secure),
                );
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(int $status, array $context): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $this->view->render('login.html.twig', $context + ['title' => 'Sign in — fastsite']));
    }
}
