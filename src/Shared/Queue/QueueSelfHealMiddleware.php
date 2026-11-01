<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Attaches {@see QueueSelfHeal} to authenticated traffic (dashboard + MCP):
 * the surfaces that stayed alive in the incident where every background
 * process was down. First in the group so even auth redirects heal.
 */
final class QueueSelfHealMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly QueueSelfHeal $heal)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->heal->maybeHeal();

        return $handler->handle($request);
    }
}
