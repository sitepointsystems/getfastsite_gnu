<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** /admin/* — role=admin only (runs after SessionAuthMiddleware). */
final class AdminGateMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(SessionAuthMiddleware::USER_ATTRIBUTE);
        if (!\is_array($user) || ($user['role'] ?? '') !== 'admin') {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], "Admins only.\n");
        }

        return $handler->handle($request);
    }
}
