<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Ops\BuildInfoService;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/build — what this container is actually running. Scope sites:read.
 *
 * Deliberately NOT folded into the public `/healthz`: that route is the
 * container healthcheck and has no auth, and a build sha plus a full native
 * library inventory handed to anonymous callers is a map of exactly which
 * CVEs to try. Behind a key it is the answer to "did the deploy land", which
 * previously required a shell on the host.
 */
final class BuildInfoHandler implements RequestHandlerInterface
{
    public function __construct(private readonly BuildInfoService $build)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::success($this->build->info());
    }
}
