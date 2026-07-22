<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /logout — ends the session and clears both cookies. */
final class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly RememberTokenService $remember,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $request->getAttribute(SessionAuthMiddleware::SESSION_ATTRIBUTE);
        $user = $request->getAttribute(SessionAuthMiddleware::USER_ATTRIBUTE);
        $secure = SessionService::isHttps($request);

        if (\is_string($sessionId)) {
            $this->sessions->logout($sessionId);
        }
        if (\is_array($user)) {
            // Signing out is an explicit "stop trusting this browser".
            $this->remember->revokeAll((int) $user['id']);
        }

        $clearSession = $secure
            ? '__Host-fsid=; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=0'
            : 'fsid=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0';

        return (new Response(302, ['Location' => '/login']))
            ->withAddedHeader('Set-Cookie', $clearSession)
            ->withAddedHeader('Set-Cookie', RememberTokenService::clearCookie($secure));
    }
}
