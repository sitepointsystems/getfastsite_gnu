<?php

declare(strict_types=1);

namespace Fastsite\Serve;

use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\MimeTypes;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\RedirectService;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
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
 * from), and a miss serves 404.html AND records the path in missing_paths —
 * feeding the dashboard's "Suggested redirects" card without the JS beacon a
 * CDN deployment needs.
 *
 * Also owns the www→apex 301 here: in direct mode there is no Cloudflare zone
 * rule in front to do it.
 */
final class DirectServeGate
{
    /** @var array<string, array{int, list<array{string, list<array{string, string}>}>}> _headers parse cache: path => [mtime, rules] */
    private array $headerRules = [];

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly RedirectService $redirects,
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

        // www.example.com → 301 to the apex when the apex is a direct site.
        if (str_starts_with($host, 'www.')) {
            $apex = substr($host, 4);
            $site = $this->sites->findByPrimaryDomain($apex);
            if ($site !== null && $site->serveMode === 'direct') {
                return new Response(301, [
                    'Location' => $this->scheme($request) . '://' . $apex . $this->pathWithQuery($request),
                    'Cache-Control' => 'no-store',
                ]);
            }

            return null;
        }

        $site = $this->sites->findByPrimaryDomain($host);
        if ($site === null || $site->serveMode !== 'direct') {
            return null;
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
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
            $redirect = $this->matchRedirect($site, $path);
            if ($redirect !== null) {
                return $redirect;
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
        return new Response(200, $headers, Stream::create($fh));
    }

    private function matchRedirect(Site $site, string $path): ?ResponseInterface
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

        foreach ($rows as $row) {
            if (\in_array((string) $row['from_path'], $candidates, true)) {
                return new Response((int) $row['status'], [
                    'Location' => (string) $row['to_path'],
                    'Cache-Control' => 'no-store',
                ]);
            }
        }

        return null;
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
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        $headers['Cache-Control'] = 'no-store';

        $page = $rootReal . \DIRECTORY_SEPARATOR . '404.html';
        $body = is_file($page) ? (string) file_get_contents($page) : "Not found.\n";
        if (!is_file($page)) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
        }

        return new Response(404, $headers, $headOnly ? '' : $body);
    }

    /**
     * Response headers from the build's own _headers file, so direct serving
     * honors exactly what the CDN would (security headers, immutable caching,
     * the scripts-mode CSP). Format (engine-generated, BuildService::headers):
     * a `/pattern` line opens a rule, indented `Name: value` lines attach to
     * it; `*` is a wildcard. All matching rules apply; later wins per name.
     *
     * @return array<string, string>
     */
    private function headersFor(string $rootReal, string $requestPath): array
    {
        $file = $rootReal . \DIRECTORY_SEPARATOR . '_headers';
        if (!is_file($file)) {
            return [];
        }

        $mtime = (int) filemtime($file);
        $cached = $this->headerRules[$file] ?? null;
        if ($cached === null || $cached[0] !== $mtime) {
            $this->headerRules = [$file => [$mtime, $this->parseHeaderRules((string) file_get_contents($file))]];
            $cached = $this->headerRules[$file];
        }

        $out = [];
        foreach ($cached[1] as [$pattern, $pairs]) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $requestPath) === 1) {
                foreach ($pairs as [$name, $value]) {
                    $out[$name] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{string, list<array{string, string}>}>
     */
    private function parseHeaderRules(string $content): array
    {
        $rules = [];
        $current = null;
        foreach (preg_split('/\r\n|\n/', $content) ?: [] as $line) {
            if ($line !== '' && $line[0] === '/') {
                if ($current !== null) {
                    $rules[] = $current;
                }
                $current = [trim($line), []];
                continue;
            }
            $trimmed = trim($line);
            if ($current !== null && $trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $current[1][] = [trim($name), trim($value)];
            }
        }
        if ($current !== null) {
            $rules[] = $current;
        }

        return $rules;
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
