<?php

declare(strict_types=1);

namespace Fastsite\Dashboard;

use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for every dashboard screen: render with the layout
 * context (user, csrf, nav sites, flash from the query string), site
 * access checks (admin → all sites, user → site_users rows), redirects.
 */
final class Dash
{
    public function __construct(
        private readonly EngineView $view,
        private readonly SessionService $sessions,
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly string $appSecret,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $user = $this->user($request);
        $sessionId = $request->getAttribute(SessionAuthMiddleware::SESSION_ATTRIBUTE);
        $query = $request->getQueryParams();

        // Flash text is only trusted when it carries a valid signature this
        // server produced (redirect() below). Otherwise an attacker could
        // craft /account?err=… to spoof an authentic-looking banner.
        $sig = isset($query['sig']) ? (string) $query['sig'] : '';
        $flashOk = isset($query['msg']) && $this->flashValid('ok', (string) $query['msg'], $sig) ? (string) $query['msg'] : '';
        $flashErr = isset($query['err']) && $this->flashValid('err', (string) $query['err'], $sig) ? (string) $query['err'] : '';

        $navSites = $this->sitesFor($user);
        $path = $request->getUri()->getPath();

        $html = $this->view->render($template, $context + [
            'user' => $user,
            'csrf' => \is_string($sessionId) ? $this->sessions->csrfToken($sessionId) : '',
            'nav_sites' => $navSites,
            'current_site' => $this->currentSite($path, $request->getCookieParams(), $navSites),
            'path' => $path,
            'flash_ok' => $flashOk,
            'flash_err' => $flashErr,
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * @return array{id: int, email: string, name: string, role: string}
     */
    public function user(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute(SessionAuthMiddleware::USER_ATTRIBUTE);
        if (!\is_array($user)) {
            throw new \RuntimeException('Dashboard handler reached without SessionAuthMiddleware.');
        }

        /** @var array{id: int, email: string, name: string, role: string} $user */
        return $user;
    }

    /**
     * Sites this user may manage — the nav list and every access check.
     *
     * @param array{id: int, role: string} $user
     * @return list<Site>
     */
    public function sitesFor(array $user): array
    {
        return $this->scopeToUser($user, $this->sites->all());
    }

    /**
     * The site the nav switcher shows as selected: the one in the current URL
     * (/sites/{handle}/…) if any, else the last-chosen one from the `fs_site`
     * cookie — validated against the sites the user may actually access, so a
     * stale or foreign handle is ignored. Returns the handle or null.
     *
     * @param array<string, mixed> $cookies
     * @param list<Site> $navSites
     */
    private function currentSite(string $path, array $cookies, array $navSites): ?string
    {
        $handles = array_map(static fn (Site $s): string => $s->handle, $navSites);

        if (preg_match('#^/sites/([^/]+)#', $path, $m) === 1 && \in_array($m[1], $handles, true)) {
            return $m[1];
        }
        $cookie = (string) ($cookies['fs_site'] ?? '');
        if ($cookie !== '' && \in_array($cookie, $handles, true)) {
            return $cookie;
        }

        return null;
    }

    /**
     * Soft-deleted sites this user may manage — the Trash view.
     *
     * @param array{id: int, role: string} $user
     * @return list<Site>
     */
    public function deletedSitesFor(array $user): array
    {
        return $this->scopeToUser($user, $this->sites->listDeleted());
    }

    /**
     * Filters a candidate site list to those the user may manage: admin → all,
     * else a direct site_users row OR a team the site belongs to (#2).
     *
     * @param array{id: int, role: string} $user
     * @param list<Site> $candidates
     * @return list<Site>
     */
    private function scopeToUser(array $user, array $candidates): array
    {
        if ($user['role'] === 'admin') {
            return $candidates;
        }
        $allowed = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->databases->engine()->fetchAllAssociative(
                'SELECT id FROM sites
                 WHERE id IN (SELECT site_id FROM site_users WHERE user_id = ?)
                    OR (team_id IS NOT NULL AND team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))',
                [$user['id'], $user['id']],
            ),
        );

        return array_values(array_filter($candidates, static fn (Site $s): bool => \in_array($s->id, $allowed, true)));
    }

    /**
     * Resolves a {site} path var with an access check. Null → the caller
     * should 404 (don't reveal whether the handle exists).
     */
    public function siteFor(ServerRequestInterface $request, string $handle): ?Site
    {
        foreach ($this->sitesFor($this->user($request)) as $site) {
            if ($site->handle === $handle) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Like {@see siteFor} but also finds soft-deleted sites the user may
     * manage — for trash actions (restore/purge) where the live lookup 404s.
     */
    public function anySiteFor(ServerRequestInterface $request, string $handle): ?Site
    {
        $user = $this->user($request);
        foreach ([...$this->sitesFor($user), ...$this->deletedSitesFor($user)] as $site) {
            if ($site->handle === $handle) {
                return $site;
            }
        }

        return null;
    }

    public function redirect(string $to, string $ok = '', string $err = ''): ResponseInterface
    {
        // Internal paths only — never an open redirect.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        $sep = str_contains($to, '?') ? '&' : '?';
        if ($ok !== '') {
            $to .= $sep . 'msg=' . rawurlencode($ok) . '&sig=' . $this->flashSig('ok', $ok);
        } elseif ($err !== '') {
            $to .= $sep . 'err=' . rawurlencode($err) . '&sig=' . $this->flashSig('err', $err);
        }

        return new Response(302, ['Location' => $to]);
    }

    private function flashSig(string $type, string $message): string
    {
        return hash_hmac('sha256', $type . ':' . $message, $this->appSecret);
    }

    private function flashValid(string $type, string $message, string $sig): bool
    {
        return $sig !== '' && hash_equals($this->flashSig($type, $message), $sig);
    }

    public function notFound(): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not found.\n");
    }
}
