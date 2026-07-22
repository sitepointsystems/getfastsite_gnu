<?php

declare(strict_types=1);

namespace Fastsite\Http;

use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HealthHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::success(['status' => 'ok']);
    }
}
