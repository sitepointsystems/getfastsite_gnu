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
    // QueueSelfHeal first: dashboard traffic is the fallback sweeper when
    // every background process is down (mutex-gated, a few ms per window).
    'web' => [
        Fastsite\Shared\Queue\QueueSelfHealMiddleware::class,
        Fastsite\Dashboard\Middleware\SessionAuthMiddleware::class,
        Fastsite\Dashboard\Middleware\CsrfMiddleware::class,
    ],
    'web_admin' => [
        Fastsite\Shared\Queue\QueueSelfHealMiddleware::class,
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
    // Published sites' contact forms post here from the visitor's browser.
    // Unauthenticated by nature (the poster is a stranger) and deliberately
    // its OWN group rather than riding on `webhook`: the rate limiter buckets
    // per group, so a hammered contact form must not 429 an integration's
    // webhook deliveries from the same NAT. No CSRF — the request is
    // cross-origin from a static page by design, and the real abuse controls
    // are the honeypot, SubmissionThrottle and server-side recipients.
    'form' => [
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
    ],
    // Upload tickets (PUT /u/{token}). Raw bytes only: the single-use token
    // IS the auth, so nothing may parse or authenticate the request first —
    // same rule as `webhook`, where the HMAC plays that part. Its OWN group
    // for the reason `form` is: the rate limiter buckets per group, so a
    // hammered upload URL must not 429 an integration's webhook deliveries
    // or a visitor's contact form from the same NAT.
    'upload' => [
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
    ],
    // MCP stays healable too — in the incident it was the surface that kept
    // answering while the workers were wedged.
    'mcp' => [
        Fastsite\Shared\Queue\QueueSelfHealMiddleware::class,
        Fastsite\Http\CorsMiddleware::class,
        Fastsite\Access\Middleware\RateLimitMiddleware::class,
        Fastsite\Mcp\McpAuthMiddleware::class,
    ],
];
