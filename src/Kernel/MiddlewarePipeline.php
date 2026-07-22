<?php

declare(strict_types=1);

namespace Fastsite\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs an ordered middleware list around a final handler. Positional and
 * immutable per call — a middleware may legally call its handler more than
 * once (retry/fallback) without corrupting the chain.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $handler,
        private readonly int $offset = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->offset])) {
            return $this->handler->handle($request);
        }

        return $this->middleware[$this->offset]->process(
            $request,
            new self($this->middleware, $this->handler, $this->offset + 1),
        );
    }
}
