<?php

declare(strict_types=1);

namespace Fastsite\Access\Middleware;

use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Caps JSON API request bodies well below the container's 512 MB media
 * limit, so a huge JSON payload can't exhaust memory before json_decode.
 * Media uploads (M4) get their own larger limit on their own routes.
 */
final class BodySizeLimitMiddleware implements MiddlewareInterface
{
    private const MAX_BYTES = 4 * 1024 * 1024; // 4 MiB

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && (int) $declared > self::MAX_BYTES) {
            return ApiResponse::error('Request body too large (max 4 MiB for JSON endpoints)', 413);
        }

        $size = $request->getBody()->getSize();
        if ($size !== null && $size > self::MAX_BYTES) {
            return ApiResponse::error('Request body too large (max 4 MiB for JSON endpoints)', 413);
        }

        return $handler->handle($request);
    }
}
