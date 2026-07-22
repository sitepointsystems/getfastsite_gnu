<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Middleware;

use Fastsite\Dashboard\Handler\LoginPageHandler;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dashboard auth: resolves the session cookie to a user (request attributes
 * `user` + `session_id`). With no live session, a valid remember-me cookie
 * transparently mints one (and rotates itself). Otherwise → /login.
 */
final class SessionAuthMiddleware implements MiddlewareInterface
{
    public const USER_ATTRIBUTE = 'user';
    public const SESSION_ATTRIBUTE = 'session_id';

    public function __construct(
        private readonly SessionService $sessions,
        private readonly RememberTokenService $remember,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $secure = SessionService::isHttps($request);
        $cookies = $request->getCookieParams();

        $sessionId = SessionService::sessionIdFromCookies($cookies, $secure);
        $user = $this->sessions->resolve($sessionId);
        if ($user !== null && $sessionId !== null) {
            return $handler->handle(
                $request->withAttribute(self::USER_ATTRIBUTE, $user)->withAttribute(self::SESSION_ATTRIBUTE, $sessionId),
            );
        }

        // Remember-me fallback — GET only: a cross-site POST must never
        // silently re-authenticate (login CSRF hardening).
        $rememberValue = RememberTokenService::cookieValue($cookies, $secure);
        if ($rememberValue !== null && strtoupper($request->getMethod()) === 'GET') {
            $consumed = $this->remember->consume($rememberValue);
            if ($consumed !== null) {
                [$userId, $freshCookie] = $consumed;
                $params = $request->getServerParams();
                $newSession = $this->sessions->mintSession(
                    $userId,
                    (string) ($params['REMOTE_ADDR'] ?? ''),
                    $request->getHeaderLine('User-Agent'),
                );
                $user = $this->sessions->resolve($newSession);
                if ($user !== null) {
                    $response = $handler->handle(
                        $request->withAttribute(self::USER_ATTRIBUTE, $user)->withAttribute(self::SESSION_ATTRIBUTE, $newSession),
                    );

                    return $response
                        ->withAddedHeader('Set-Cookie', SessionService::setCookie($newSession, $secure))
                        ->withAddedHeader('Set-Cookie', RememberTokenService::setCookie($freshCookie, $secure));
                }
            }
        }

        $next = LoginPageHandler::safeNext($request->getUri()->getPath());
        $location = '/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : '');

        return new Response(302, ['Location' => $location]);
    }
}
