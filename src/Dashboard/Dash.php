<?php

declare(strict_types=1);

namespace Fastsite\Dashboard;

use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for every dashboard screen: render with the layout
 * context (user, csrf, nav sites, flash from the query string), site
 * access checks (admin → all sites, user → site_users rows), redirects.
 */
final class Dash
{
    /**
     * Last-chosen workspace (id, or absent = all). Server-set on the POST
     * select action ONLY — never read from a query parameter — and validated
     * against workspace access on every read, so a stale or foreign id is
     * silently treated as "all".
     */
    public const WORKSPACE_COOKIE = 'fs_ws';

    public function __construct(
        private readonly EngineView $view,
        private readonly SessionService $sessions,
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly TeamService $teams,
        private readonly WorkspaceService $workspaces,
        private readonly string $appSecret,
        // Nullable + defaulted so existing positional test fixtures keep
        // working; php-di injects the real service in the app.
        private readonly ?\Fastsite\Identity\Service\AvatarService $avatars = null,
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

        // The nav (and every screen's site list) shows only the selected
        // workspace's sites; access checks (siteFor) stay on the full list.
        $navSites = $this->visibleSitesFor($request);
        $path = $request->getUri()->getPath();

        $html = $this->view->render($template, $context + [
            'user' => $user,
            'csrf' => \is_string($sessionId) ? $this->sessions->csrfToken($sessionId) : '',
            'nav_sites' => $navSites,
            'workspaces' => $this->workspaces->listForUser((int) $user['id']),
            'current_workspace' => $this->selectedWorkspaceId($request),
            'avatar_url' => $this->avatars?->urlFor((int) $user['id']),
            'pending_invites' => $this->teams->pendingInviteCount((string) $user['email']),
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
     * The workspace the user last selected (via the POST select action), or
     * null for "all". A cookie pointing at a workspace the user cannot access
     * (revoked, deleted, foreign) is ignored — selection itself 403s in
     * WorkspacesActionHandler; a stale cookie must never lock the UI.
     */
    public function selectedWorkspaceId(ServerRequestInterface $request): ?int
    {
        $raw = (string) ($request->getCookieParams()[self::WORKSPACE_COOKIE] ?? '');
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        $user = $this->user($request);

        return $this->workspaces->canAccess((int) $user['id'], $id) ? $id : null;
    }

    /**
     * The sites every screen should LIST: the user's accessible sites,
     * narrowed to the selected workspace when one is chosen. Access itself is
     * unchanged — {@see siteFor} resolves against the full accessible set so
     * a direct link to your own site keeps working across workspaces.
     *
     * @return list<Site>
     */
    public function visibleSitesFor(ServerRequestInterface $request): array
    {
        $sites = $this->sitesFor($this->user($request));
        $workspaceId = $this->selectedWorkspaceId($request);
        if ($workspaceId === null) {
            return $sites;
        }

        return array_values(array_filter($sites, static fn (Site $s): bool => $s->workspaceId === $workspaceId));
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
     * Filters a candidate site list to those the user may manage. THE access
     * model: admin → all; everyone else reaches a site ONLY as its owner
     * (site_users role 'owner', set for the creator) or through a team the
     * site is attached to. There is deliberately no third, per-user grant.
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
                "SELECT id FROM sites
                 WHERE id IN (SELECT site_id FROM site_users WHERE user_id = ? AND role = 'owner')
                    OR (team_id IS NOT NULL AND team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))",
                [$user['id'], $user['id']],
            ),
        );

        return array_values(array_filter($candidates, static fn (Site $s): bool => \in_array($s->id, $allowed, true)));
    }

    /**
     * May this user change the site's SETTINGS (base_url, repo, go-live …)?
     * Admin, or the site's recorded owner (site_users role 'owner' — set for
     * the creator). Plain members/editors of the site see settings read-only.
     *
     * @param array{id: int, role: string} $user
     */
    public function canManageSite(array $user, Site $site): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }

        return (bool) $this->databases->engine()->fetchOne(
            "SELECT 1 FROM site_users WHERE site_id = ? AND user_id = ? AND role = 'owner'",
            [$site->id, $user['id']],
        );
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

    /** A real 403 — used when a user tries a workspace they have no access to. */
    public function forbidden(string $message = 'Forbidden.'): ResponseInterface
    {
        return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], $message . "\n");
    }

    /**
     * The upload selection, whether it arrived as the dialog's single `file`
     * or the no-JS fallback's `files[]` array. Entries the browser sent empty
     * (a file input left blank next to a filled URL field) are dropped;
     * entries with a real upload error are kept so the caller can report them.
     *
     * @return list<\Psr\Http\Message\UploadedFileInterface>
     */
    public static function uploads(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $list = [];
        $one = $files['file'] ?? null;
        if ($one instanceof \Psr\Http\Message\UploadedFileInterface && $one->getError() !== UPLOAD_ERR_NO_FILE) {
            $list[] = $one;
        }
        foreach (\is_array($files['files'] ?? null) ? $files['files'] : [] as $f) {
            if ($f instanceof \Psr\Http\Message\UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE) {
                $list[] = $f;
            }
        }

        return $list;
    }

    /** A user-facing reason for a PHP-level upload failure. */
    public static function uploadProblem(?int $error): string
    {
        return match ($error) {
            null, UPLOAD_ERR_NO_FILE => 'No file arrived — it may exceed the server upload size limit.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The upload arrived incomplete — try again.',
            default => 'The upload failed (PHP upload error ' . $error . ').',
        };
    }

    /**
     * Fold per-file failure lines into ONE bounded string. The flash rides
     * the redirect's query string, so an unbounded concatenation of verbose
     * errors from a big batch would overflow URL/header limits and turn the
     * report into a 414 error page.
     *
     * @param list<string> $failed
     */
    public static function foldFailures(array $failed, int $keep = 3): string
    {
        if ($failed === []) {
            return '';
        }
        $shown = array_map(
            static fn (string $m): string => mb_strlen($m) > 200 ? mb_substr($m, 0, 197) . '…' : $m,
            \array_slice($failed, 0, $keep),
        );
        $rest = \count($failed) - \count($shown);

        return implode(' · ', $shown) . ($rest > 0 ? ' · … and ' . $rest . ' more failed' : '');
    }
}
