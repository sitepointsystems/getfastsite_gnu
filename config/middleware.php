<?php

declare(strict_types=1);

/**
 * Middleware groups. Each group is an ordered list of middleware class names
 * (Psr\Http\Server\MiddlewareInterface) applied around the route handler.
 *
 * Groups fill up as milestones land:
 *   public  — no auth (health, published previews)
 *   web     — session + CSRF (dashboards)          [M3/M11]
 *   api     — RateLimit → ApiKeyAuth → ScopeCheck  [M3]
 *   webhook — raw-body HMAC verification            [M5]
 *   mcp     — OAuth bearer                          [M9]
 *
 * @return array<string, list<class-string>>
 */
return [
    // CORS on the discovery surface so browser MCP clients can fetch it.
    'public' => [
        Fastsite\Http\CorsMiddleware::class,
    ],
    // Unauthenticated but abuse-prone endpoints (login, OAuth token/register).
    // The always-on per-IP cap in RateLimitMiddleware is the HTTP-layer
    // backstop on top of the per-account/per-user login throttles.
    'auth' => [
        Fastsite\Http\CorsMiddleware::class,
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
    ],
    // Dashboards: session (with remember-me fallback) then CSRF on writes.
    'web' => [
        Fastsite\Dashboard\Middleware\SessionAuthMiddleware::class,
        Fastsite\Dashboard\Middleware\CsrfMiddleware::class,
    ],
    'web_admin' => [
        Fastsite\Dashboard\Middleware\SessionAuthMiddleware::class,
        Fastsite\Dashboard\Middleware\AdminGateMiddleware::class,
        Fastsite\Dashboard\Middleware\CsrfMiddleware::class,
    ],
    'api' => [
        Fastsite\Access\Middleware\BodySizeLimitMiddleware::class,
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
        Fastsite\Access\Middleware\ApiKeyAuthMiddleware::class,
        Fastsite\Access\Middleware\ScopeCheckMiddleware::class,
        Fastsite\Access\Middleware\IdempotencyMiddleware::class,
    ],
    // Media uploads: same auth chain WITHOUT the small JSON body cap —
    // MediaService enforces per-type size limits (img 15MB / video 500MB).
    'api_upload' => [
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
        Fastsite\Access\Middleware\ApiKeyAuthMiddleware::class,
        Fastsite\Access\Middleware\ScopeCheckMiddleware::class,
        Fastsite\Access\Middleware\IdempotencyMiddleware::class,
    ],
    // Raw body only — the HMAC over the exact bytes IS the auth, so no
    // parsing/auth middleware may touch the request first.
    'webhook' => [
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
    ],
    'mcp' => [
        Fastsite\Http\CorsMiddleware::class,
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
        Fastsite\Mcp\McpAuthMiddleware::class,
    ],
];
