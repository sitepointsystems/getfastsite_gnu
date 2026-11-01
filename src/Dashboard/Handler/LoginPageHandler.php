<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /login — already signed in? straight to the dashboard. */
final class LoginPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EngineView $view,
        private readonly SessionService $sessions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = SessionService::sessionIdFromCookies($request->getCookieParams(), SessionService::isHttps($request));
        if ($this->sessions->resolve($sessionId) !== null) {
            return new Response(302, ['Location' => '/']);
        }

        $query = $request->getQueryParams();

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->view->render('login.html.twig', [
            'title' => 'Sign in — fastsite',
            'next' => self::safeNext((string) ($query['next'] ?? '')),
            'challenge' => '',
            'error' => '',
            'remember' => false,
        ]));
    }

    /**
     * A safe internal redirect target: must be an absolute path whose second
     * character is neither '/' nor '\' — browsers normalize '\' to '/', so
     * "/\evil.com" would otherwise resolve as the protocol-relative
     * "//evil.com" (open redirect). Anything else falls back to '/'.
     */
    public static function safeNext(string $next): string
    {
        if (!str_starts_with($next, '/')) {
            return '/';
        }
        if (isset($next[1]) && ($next[1] === '/' || $next[1] === '\\')) {
            return '/';
        }

        return $next;
    }
}
