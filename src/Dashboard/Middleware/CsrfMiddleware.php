<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Middleware;

use Fastsite\Identity\Service\SessionService;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session-bound CSRF for the dashboard (runs AFTER SessionAuthMiddleware).
 * Every non-GET/HEAD request must carry the session's token in `_csrf`.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionService $sessions)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (\in_array($method, ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $sessionId = $request->getAttribute(SessionAuthMiddleware::SESSION_ATTRIBUTE);
        $body = $request->getParsedBody();
        $token = \is_array($body) ? (string) ($body['_csrf'] ?? '') : '';

        if (!\is_string($sessionId) || $token === '' || !$this->sessions->verifyCsrf($sessionId, $token)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], "Invalid or missing CSRF token — reload the page and try again.\n");
        }

        return $handler->handle($request);
    }
}
