<?php

declare(strict_types=1);

namespace Fastsite\Access\Middleware;

use Fastsite\Kernel\Route;
use Fastsite\Shared\Http\ApiResponse;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fixed-window rate limit (Redis INCR + EXPIRE). The per-IP cap is ALWAYS
 * enforced — it is the backstop an attacker can't evade by rotating the
 * X-API-Key header (REMOTE_ADDR isn't forgeable within a request). A
 * per-key cap layers on top, bucketed by the stable public key_id (not the
 * secret) so a fixed key with rotating secrets shares one bucket.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const PER_KEY = 120;
    private const PER_IP = 60;

    public function __construct(private readonly Client $redis)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $window = intdiv(time(), 60);
        $params = $request->getServerParams();
        $ip = (string) ($params['REMOTE_ADDR'] ?? 'unknown');

        // Bucket per middleware group so a burst on one surface (e.g. a busy
        // API client) can't 429 an unrelated surface (e.g. login) for the
        // same IP. The nginx real_ip config makes $ip the true client behind
        // the proxy — without it every client would share one bucket.
        $route = $request->getAttribute(Route::REQUEST_ATTRIBUTE);
        $group = $route instanceof Route ? $route->middlewareGroup : 'default';

        // Always-on per-IP cap — the un-bypassable backstop.
        if ($this->hit($group . ':i:' . $ip, $window, self::PER_IP)) {
            return $this->tooMany();
        }

        // Additional per-key cap, keyed on the public key_id prefix only.
        $token = $request->getHeaderLine('X-API-Key');
        if ($token !== '' && preg_match('/^fs_([A-Za-z0-9]{8})_/', $token, $m) === 1) {
            if ($this->hit('k:' . $m[1], $window, self::PER_KEY)) {
                return $this->tooMany();
            }
        }

        return $handler->handle($request);
    }

    private function hit(string $id, int $window, int $limit): bool
    {
        $key = 'fastsite:rl:' . $id . ':' . $window;
        $count = (int) $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, 90);
        }

        return $count > $limit;
    }

    private function tooMany(): ResponseInterface
    {
        return ApiResponse::error('Rate limit exceeded', 429)
            ->withHeader('Retry-After', (string) (60 - (time() % 60)));
    }
}
