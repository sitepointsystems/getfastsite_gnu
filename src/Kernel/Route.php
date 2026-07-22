<?php

declare(strict_types=1);

namespace Fastsite\Kernel;

/**
 * One route table entry (config/routes.php).
 */
final readonly class Route
{
    public const REQUEST_ATTRIBUTE = 'route';

    /**
     * @param class-string $handler Psr\Http\Server\RequestHandlerInterface implementation
     * @param string $middlewareGroup key into config/middleware.php
     * @param string|null $scope required scope (enforced by ScopeCheckMiddleware in api group)
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public string $middlewareGroup = 'public',
        public ?string $scope = null,
    ) {
    }
}
