<?php

declare(strict_types=1);

namespace Fastsite\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The engine's external base URL (scheme://host[:port]) — the origin an
 * outside client (an MCP OAuth client, a browser) actually reached us at.
 *
 * OAuth discovery advertises absolute URLs (issuer, endpoints, resource
 * metadata). If those point at localhost, a remote client (claude.ai,
 * Claude Desktop) can't fetch them and registration fails. So: use the
 * configured APP_URL when it is a real public URL (stable OAuth issuer), and
 * otherwise DERIVE the origin from the request — honoring the reverse proxy's
 * X-Forwarded-Proto / X-Forwarded-Host (Coolify/Traefik terminate TLS, so the
 * app sees http internally but the public origin is https). This makes OAuth
 * discovery work out-of-the-box even when APP_URL was left at its default.
 */
final class PublicUrl
{
    public static function base(ServerRequestInterface $request, string $configured): string
    {
        $configured = rtrim(trim($configured), '/');
        if ($configured !== '' && !self::isLocalish($configured)) {
            return $configured; // explicit, real public URL wins (stable issuer)
        }

        $scheme = self::forwarded($request, 'X-Forwarded-Proto')
            ?? ($request->getUri()->getScheme() ?: 'https');

        $host = self::forwarded($request, 'X-Forwarded-Host')
            ?? self::forwarded($request, 'Host')
            ?? ($request->getUri()->getHost() ?: '');

        // Only trust a well-formed hostname[:port]; anything else (spoofed /
        // malformed Host) falls back so metadata can't be poisoned.
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) !== 1) {
            return $configured !== '' ? $configured : 'http://localhost';
        }

        return strtolower($scheme) . '://' . $host;
    }

    private static function isLocalish(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '0.0.0.0'
            || $host === '::1'
            || str_ends_with($host, '.local');
    }

    /** First value of a possibly comma-separated forwarded header, trimmed. */
    private static function forwarded(ServerRequestInterface $request, string $header): ?string
    {
        $value = $request->getHeaderLine($header);
        if ($value === '') {
            return null;
        }
        $first = trim(explode(',', $value)[0]);

        return $first !== '' ? $first : null;
    }
}
