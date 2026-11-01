<?php

declare(strict_types=1);

namespace Fastsite\Serve;

use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\BuildHeaders;
use Fastsite\Shared\Http\MimeTypes;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\RedirectService;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves a direct-mode site's build/ tree straight from this server, selected
 * by Host header — the alternative to pushing the build to a repo a CDN
 * watches. Runs BEFORE the router in Application::dispatch, so on a site's
 * domain none of the engine surface (/login, /api, /mcp, dashboards) is
 * reachable: the request is either a site file, a redirect, or the site's 404.
 *
 * Honors the build's own contract files: response headers come from the
 * generated _headers (so the scripts-mode CSP relaxation applies unchanged),
 * redirects come from the redirects table (same source _redirects is written
 * from) — including 410 rules, which a CDN deployment can only degrade to a
 * 404 — and a miss serves 404.html AND records the path in missing_paths,
 * feeding the dashboard's "Suggested redirects" card without the JS beacon a
 * CDN deployment needs.
 *
 * Also owns the host-level 301 here: in direct mode there is no Cloudflare
 * zone rule in front, so this is what sends visitors to the site's canonical
 * host — www→apex, or apex→www when the operator chose www.
 */
final class DirectServeGate
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly RedirectService $redirects,
        private readonly BuildHeaders $buildHeaders,
        private readonly string $appUrl,
    ) {
    }

    public function intercept(ServerRequestInterface $request): ?ResponseInterface
    {
        $host = strtolower($request->getUri()->getHost());
        if ($host === '') {
            return null;
        }

        // The engine's own host is never a site: dashboards/API/MCP proceed to
        // the router with zero extra queries.
        $appHost = strtolower((string) (parse_url($this->appUrl, PHP_URL_HOST) ?: ''));
        if ($host === $appHost) {
            return null;
        }

        // Both host forms resolve to the same site row (primary_domain is the
        // apex); which one visitors END on is the site's canonical_host.
        $apex = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $site = $this->sites->findByPrimaryDomain($apex);
        if ($site === null || $site->serveMode !== 'direct') {
            return null;
        }

        $canonical = strtolower($site->canonicalDomain());
        if ($canonical !== '' && $host !== $canonical) {
            return new Response(301, [
                'Location' => $this->scheme($request) . '://' . $canonical . $this->pathWithQuery($request),
                'Cache-Control' => 'no-store',
            ]);
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            // A redirect rule still answers a POST: 307/308 preserve method and
            // body, so a beacon aimed at a moved endpoint reaches its new home
            // instead of dying on a 405. Gone rules and misses keep the 405.
            $rule = $this->resolveRule(
                $site,
                rawurldecode($request->getUri()->getPath()),
                $request->getUri()->getQuery(),
            );
            if ($rule !== null && $rule['status'] !== RedirectService::GONE) {
                return new Response($rule['status'], [
                    'Location' => $rule['to'],
                    'Cache-Control' => 'no-store',
                ]);
            }

            return new Response(405, ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8'], "Method not allowed.\n");
        }

        return $this->serve($site, $request, $method === 'HEAD');
    }

    private function serve(Site $site, ServerRequestInterface $request, bool $headOnly): ResponseInterface
    {
        $buildDir = $this->databases->sitePath($site->handle) . '/build';
        $rootReal = realpath($buildDir);
        if ($rootReal === false) {
            // Direct mode enabled but never built — an honest empty state, not
            // an engine error page on the public domain.
            return new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '30'], "This site has not been built yet.\n");
        }

        $path = rawurldecode($request->getUri()->getPath());
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return $this->notFound($site, $request, $rootReal, $headOnly);
        }

        // Directory-style URLs: the build writes blog/x/index.html, canonical
        // links use the trailing slash. A slashless hit on a directory 301s to
        // the canonical form (CDN behavior) instead of duplicating content.
        $rel = ltrim($path, '/');
        $serveRel = $rel === '' || str_ends_with($rel, '/') ? $rel . 'index.html' : $rel;

        $target = realpath($buildDir . '/' . $serveRel);
        $contained = $target !== false && (str_starts_with($target, $rootReal . \DIRECTORY_SEPARATOR) || $target === $rootReal);

        if ((!$contained || !is_file((string) $target)) && $rel !== '' && !str_ends_with($rel, '/')) {
            $asDir = realpath($buildDir . '/' . $rel . '/index.html');
            if ($asDir !== false && str_starts_with($asDir, $rootReal . \DIRECTORY_SEPARATOR) && is_file($asDir)) {
                return new Response(301, [
                    'Location' => $path . '/',
                    'Cache-Control' => 'no-store',
                ]);
            }
        }

        if (!$contained || !is_file((string) $target)) {
            $rule = $this->matchRedirect($site, $path, $request->getUri()->getQuery(), $rootReal, $headOnly);
            if ($rule !== null) {
                return $rule;
            }

            return $this->notFound($site, $request, $rootReal, $headOnly);
        }
        $target = (string) $target;

        $headers = $this->headersFor($rootReal, $path);
        $headers['Content-Type'] = MimeTypes::for($target);

        // HTML is not immutable-cached, so give repeat visitors conditional
        // GETs. mtime is the honest validator for an incrementally-built tree.
        $mtime = (int) filemtime($target);
        $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $since = $request->getHeaderLine('If-Modified-Since');
        if ($since !== '' && strtotime($since) >= $mtime) {
            return new Response(304, $headers);
        }

        $headers['Content-Length'] = (string) filesize($target);
        if ($headOnly) {
            return new Response(200, $headers);
        }

        $fh = @fopen($target, 'rb');
        if ($fh === false) {
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], "Could not read file.\n");
        }

        // A stream body — Application::emit reads it in chunks, so a large
        // video never materializes as one PHP string.
        return new Response(200, $headers, Utils::streamFor($fh));
    }

    /**
     * A rule from the redirects table: a 3xx to another path, or 410 Gone —
     * the one place in the stack where a 410 is actually answerable, since
     * a CDN's _redirects file cannot express it.
     */
    private function matchRedirect(Site $site, string $path, string $query, string $rootReal, bool $headOnly): ?ResponseInterface
    {
        $rule = $this->resolveRule($site, $path, $query);
        if ($rule === null) {
            return null;
        }

        if ($rule['status'] !== RedirectService::GONE) {
            return new Response($rule['status'], [
                'Location' => $rule['to'],
                'Cache-Control' => 'no-store',
            ]);
        }

        // Gone: no Location, and the hit is INTENTIONAL — it is not
        // recorded in missing_paths, or the dashboard would keep asking
        // the operator to fix a URL they deliberately retired.
        //
        // A site with a 410 error page gets ITS page; otherwise the 404 is
        // still the closest body the shell provides for "not here". Before
        // error pages existed, a 410 always served the 404's bytes — `<h1>404`
        // and all — under a 410 status, which told the visitor the opposite of
        // what the status said.
        [$body, $isHtml] = $this->errorBody($rootReal, $path, 410);
        $headers = $this->headersFor($rootReal, $path);
        $headers['Content-Type'] = $isHtml ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8';
        $headers['Cache-Control'] = 'no-store';

        return new Response(410, $headers, $headOnly ? '' : ($body ?? "Gone.\n"));
    }

    /**
     * The best body in the built tree for one error status on one request path.
     *
     * Two fallback axes, in this order:
     *
     * 1. STATUS: the requested code's own page, then 404's. A site that never
     *    made a 410 page still gets something better than a bare status line.
     * 2. LOCALE: the page under the request's own locale prefix, then the
     *    root one. `notFound()` used to read only the build-root `404.html`,
     *    so every miss under `/dk/` served the ENGLISH 404 even though the
     *    build had been emitting `dk/404.html` all along.
     *
     * @return array{0: ?string, 1: bool} body (null = nothing found), is-HTML
     */
    private function errorBody(string $rootReal, string $path, int $status): array
    {
        // First path segment, when it looks like a locale code. Not validated
        // against the site's locale list on purpose: the file either exists in
        // the tree or it does not, and `is_file` is the cheaper, truer check.
        $prefix = '';
        if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2,8})?)(?:/|$)#i', $path, $m) === 1) {
            $prefix = strtolower($m[1]) . \DIRECTORY_SEPARATOR;
        }

        $dirs = $prefix === '' ? [''] : [$prefix, ''];
        $codes = $status === 404 ? [404] : [$status, 404];

        foreach ($codes as $code) {
            foreach ($dirs as $dir) {
                $file = $rootReal . \DIRECTORY_SEPARATOR . $dir . $code . '.html';
                if (is_file($file)) {
                    return [(string) file_get_contents($file), true];
                }
            }
        }

        return [null, false];
    }

    /**
     * The rule answering `$path`, wildcards included — direct mode must honor
     * the same `/old/*` → `/new/:splat` rules the CDN's `_redirects` file
     * would. An exact rule always beats a wildcard regardless of row order;
     * among wildcards the longest prefix wins. `:splat` in the target is the
     * matched remainder, and the request's query string is carried over on a
     * wildcard match (the moved asset keeps its cache-busting params). For a
     * 410 rule `to` is empty, as stored.
     *
     * @return array{to: string, status: int}|null
     */
    private function resolveRule(Site $site, string $path, string $query): ?array
    {
        try {
            $rows = $this->redirects->list($site->handle);
        } catch (\Throwable) {
            return null;
        }

        $normalized = '/' . ltrim($path, '/');
        $candidates = [$normalized];
        // Be forgiving about the trailing slash on both sides of the rule.
        $candidates[] = str_ends_with($normalized, '/') ? rtrim($normalized, '/') : $normalized . '/';
        if ($normalized !== '/' && rtrim($normalized, '/') === '') {
            $candidates = ['/'];
        }

        $best = null;
        $bestPrefixLen = -1;
        foreach ($rows as $row) {
            $from = (string) $row['from_path'];
            $status = (int) $row['status'];

            if (\in_array($from, $candidates, true)) {
                return ['to' => (string) $row['to_path'], 'status' => $status];
            }

            if (!str_ends_with($from, '/*')) {
                continue;
            }
            $prefix = substr($from, 0, -1); // "/app/*" -> "/app/"
            $base = rtrim($prefix, '/');    // "/app/"  -> "/app"
            if (!str_starts_with($normalized, $prefix) && $normalized !== $base) {
                continue;
            }
            if (\strlen($prefix) <= $bestPrefixLen) {
                continue;
            }

            $to = (string) $row['to_path'];
            if ($status !== RedirectService::GONE) {
                $splat = $normalized === $base ? '' : substr($normalized, \strlen($prefix));
                $to = str_replace(':splat', $splat, $to);
                if ($query !== '') {
                    $to .= (str_contains($to, '?') ? '&' : '?') . $query;
                }
            }

            $best = ['to' => $to, 'status' => $status];
            $bestPrefixLen = \strlen($prefix);
        }

        return $best;
    }

    private function notFound(Site $site, ServerRequestInterface $request, string $rootReal, bool $headOnly): ResponseInterface
    {
        // Feed the dashboard's "Suggested redirects" — recording must never
        // break serving the 404 itself.
        try {
            $this->redirects->recordMissing(
                $site->handle,
                $request->getUri()->getPath(),
                $request->getHeaderLine('Referer'),
            );
        } catch (\Throwable) {
            // best effort
        }

        $headers = $this->headersFor($rootReal, $request->getUri()->getPath());
        $headers['Cache-Control'] = 'no-store';

        // Locale-aware: a miss under /dk/ serves dk/404.html when the build
        // emitted one, falling back to the root page. See errorBody().
        [$body, $isHtml] = $this->errorBody($rootReal, $request->getUri()->getPath(), 404);
        $headers['Content-Type'] = $isHtml ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8';

        return new Response(404, $headers, $headOnly ? '' : ($body ?? "Not found.\n"));
    }

    /**
     * Response headers from the build's own _headers file, so direct serving
     * honors exactly what the CDN would (security headers, immutable caching,
     * the scripts-mode CSP). In direct mode the site is at the domain root, so
     * the request path IS the in-tree path.
     *
     * @return array<string, string>
     */
    private function headersFor(string $rootReal, string $requestPath): array
    {
        return $this->buildHeaders->for($rootReal, $requestPath);
    }

    private function scheme(ServerRequestInterface $request): string
    {
        // Behind the Coolify/Traefik proxy the internal hop is http; the
        // original scheme arrives in X-Forwarded-Proto (first token only).
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
        if ($proto === 'https' || $proto === 'http') {
            return $proto;
        }

        return $request->getUri()->getScheme() ?: 'https';
    }

    private function pathWithQuery(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();

        return $path . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
    }
}
