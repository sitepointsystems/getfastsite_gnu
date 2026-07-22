<?php

declare(strict_types=1);

namespace Fastsite\Access\Middleware;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ApiKeyService $keys)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getHeaderLine('X-API-Key');
        if ($token === '') {
            return ApiResponse::error('Missing X-API-Key header', 401);
        }

        $params = $request->getServerParams();
        $access = $this->keys->verify($token, (string) ($params['REMOTE_ADDR'] ?? ''));
        if ($access === null) {
            return ApiResponse::error('Invalid API key', 401);
        }

        return $handler->handle($request->withAttribute(AccessContext::REQUEST_ATTRIBUTE, $access));
    }
}
