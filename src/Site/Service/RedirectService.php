<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Manual redirects + 404 capture (#15). Redirects live in the site DB
 * `redirects` table the build already emits to `_redirects`. Missing paths
 * (non-200s) are recorded so the dashboard can suggest one-click redirects.
 */
final class RedirectService
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $handle): array
    {
        return $this->databases->site($handle)->fetchAllAssociative(
            'SELECT id, from_path, to_path, status, created_at FROM redirects ORDER BY from_path',
        );
    }

    public function save(string $handle, string $from, string $to, int $status = 301): void
    {
        $from = self::normalizePath($from);
        $to = trim($to);
        if ($from === '' || $to === '') {
            throw new \InvalidArgumentException('Both from and to paths are required.');
        }
        if ($from === $to) {
            throw new \InvalidArgumentException('A redirect cannot point to itself.');
        }
        if (!\in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }

        $this->databases->site($handle)->executeStatement(
            'INSERT INTO redirects (from_path, to_path, status, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (from_path) DO UPDATE SET to_path = excluded.to_path, status = excluded.status',
            [$from, $to, $status, $this->clock->iso()],
        );
    }

    public function delete(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('DELETE FROM redirects WHERE id = ?', [$id]);
    }

    /** Record a non-200 request path (idempotent — bumps the hit counter). */
    public function recordMissing(string $handle, string $path, string $referrer = ''): void
    {
        $path = self::normalizePath($path);
        if ($path === '' || mb_strlen($path) > 2048) {
            return;
        }
        $now = $this->clock->iso();
        $this->databases->site($handle)->executeStatement(
            'INSERT INTO missing_paths (path, hits, referrer, first_seen_at, last_seen_at) VALUES (?, 1, ?, ?, ?)
             ON CONFLICT (path) DO UPDATE SET hits = hits + 1, last_seen_at = excluded.last_seen_at',
            [$path, mb_substr($referrer, 0, 2048), $now, $now],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMissing(string $handle): array
    {
        try {
            return $this->databases->site($handle)->fetchAllAssociative(
                'SELECT id, path, hits, referrer, last_seen_at FROM missing_paths
                 WHERE resolved = 0 ORDER BY hits DESC, last_seen_at DESC LIMIT 100',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Create a redirect from a captured 404 and mark it resolved. */
    public function resolveMissing(string $handle, int $id, string $to, int $status = 301): void
    {
        $row = $this->databases->site($handle)->fetchAssociative('SELECT path FROM missing_paths WHERE id = ?', [$id]);
        if ($row === false) {
            throw new \InvalidArgumentException('Missing-path record not found.');
        }
        $this->save($handle, (string) $row['path'], $to, $status);
        $this->databases->site($handle)->executeStatement('UPDATE missing_paths SET resolved = 1 WHERE id = ?', [$id]);
    }

    public function clearMissing(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('UPDATE missing_paths SET resolved = 1 WHERE id = ?', [$id]);
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // Store as a root-absolute path (strip any host the collector sent).
        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        }

        return '/' . ltrim($path, '/');
    }
}
