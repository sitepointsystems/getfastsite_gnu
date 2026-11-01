<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Manual redirects + 404 capture (#15). Redirects live in the site DB
 * `redirects` table the build already emits to `_redirects`. Missing paths
 * (non-200s) are recorded so the dashboard can suggest one-click redirects.
 *
 * A rule is either a 3xx to another path, or {@see self::GONE} — 410, the
 * "this URL is permanently removed and has no successor" answer that gets a
 * dead page out of the index far faster than a soft 404. A 410 row carries no
 * target. It is served for real in direct mode; a CDN deployment can only
 * degrade it to the site's 404 page (see BuildService::redirectsFile).
 */
final class RedirectService
{
    /** 410 Gone — a rule with no target. */
    public const GONE = 410;

    /** Sort keys the Suggested-redirects list accepts; anything else is `hits`. */
    public const MISSING_SORTS = ['hits', 'path', 'recent'];

    /**
     * Rows listed at once. Raised from 100 when bulk selection arrived: "select
     * all" has to mean every matching row once a filter is applied, or the
     * checkbox quietly lies. The screen prints the true total beside it for the
     * case where it still does not fit, and PHP's default max_input_vars (1000)
     * comfortably covers this many checkboxes in one POST.
     */
    private const MISSING_LIMIT = 500;

    /** Statuses a rule may carry; anything else is coerced to 301. */
    private const STATUSES = [301, 302, 307, 308, self::GONE];

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

    /**
     * Create or replace the rule for `$from`. Returns the row as stored, so
     * callers echo the normalized path and the coerced status rather than
     * whatever the client sent.
     *
     * @return array{from_path: string, to_path: string, status: int}
     */
    public function save(string $handle, string $from, string $to, int $status = 301): array
    {
        $from = self::normalizePath($from);
        $to = trim($to);
        if (!\in_array($status, self::STATUSES, true)) {
            $status = 301;
        }
        if ($from === '') {
            throw new \InvalidArgumentException('A from path is required.');
        }

        if ($status === self::GONE) {
            // Gone has no successor. Any target sent alongside is dropped so
            // the row can never be read as "redirect somewhere" later.
            $to = '';
        } elseif ($to === '') {
            throw new \InvalidArgumentException('A to path is required — use status 410 to mark the path permanently gone.');
        } elseif ($from === $to) {
            throw new \InvalidArgumentException('A redirect cannot point to itself.');
        }

        $this->databases->site($handle)->executeStatement(
            'INSERT INTO redirects (from_path, to_path, status, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (from_path) DO UPDATE SET to_path = excluded.to_path, status = excluded.status',
            [$from, $to, $status, $this->clock->iso()],
        );

        return ['from_path' => $from, 'to_path' => $to, 'status' => $status];
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
    public function listMissing(string $handle, string $q = '', string $sort = 'hits'): array
    {
        $order = match ($sort) {
            // Path order is the whole point of sorting here: it groups a
            // directory together so /media/ can be dealt with in one pass
            // instead of one row at a time.
            'path' => 'path ASC',
            'recent' => 'last_seen_at DESC, hits DESC',
            default => 'hits DESC, last_seen_at DESC',
        };

        try {
            return $this->databases->site($handle)->fetchAllAssociative(
                "SELECT id, path, hits, referrer, last_seen_at FROM missing_paths
                 WHERE resolved = 0 AND path LIKE ? ESCAPE '\\' ORDER BY " . $order . ' LIMIT ' . self::MISSING_LIMIT,
                [self::likeTerm($q)],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** How many unresolved paths match, which is usually more than are shown. */
    public function countMissing(string $handle, string $q = ''): int
    {
        try {
            return (int) $this->databases->site($handle)->fetchOne(
                "SELECT COUNT(*) FROM missing_paths WHERE resolved = 0 AND path LIKE ? ESCAPE '\\'",
                [self::likeTerm($q)],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A SUBSTRING match that treats the typed text literally. `%` and `_` are
     * LIKE wildcards, and a filter box is not the place to discover that: a
     * path containing one is ordinary, and silently turning it into a wildcard
     * would select rows the user never saw.
     */
    private static function likeTerm(string $q): string
    {
        $q = trim($q);
        if ($q === '') {
            return '%';
        }

        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    /**
     * Create a rule from a captured 404 and mark it resolved — a 3xx to the
     * replacement page, or status 410 (empty `$to`) when there is none.
     */
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

    /**
     * Dismiss a selection. Nothing is published — a dismissed suggestion only
     * stops being suggested; no rule is written and no page changes.
     *
     * @param array<array-key, mixed> $ids
     */
    public function clearMissingMany(string $handle, array $ids): int
    {
        $ids = self::cleanIds($ids);
        if ($ids === []) {
            return 0;
        }

        return (int) $this->databases->site($handle)->executeStatement(
            'UPDATE missing_paths SET resolved = 1 WHERE id IN (' . implode(',', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        );
    }

    /**
     * Resolve a selection at ONE status, which in practice means 410.
     *
     * There is deliberately no bulk 3xx: a redirect needs its own target per
     * path, so the only thing a set could share is a single destination, and
     * sending forty different URLs to one page is worse than leaving them
     * 404ing. Each id goes through resolveMissing(), so rule creation stays a
     * single code path rather than two that drift.
     *
     * @param array<array-key, mixed> $ids
     */
    public function resolveMissingMany(string $handle, array $ids, int $status = self::GONE): int
    {
        $done = 0;
        foreach (self::cleanIds($ids) as $id) {
            try {
                $this->resolveMissing($handle, $id, '', $status);
                ++$done;
            } catch (\InvalidArgumentException) {
                // A row someone else resolved between the page render and this
                // submit is not a reason to abandon the rest of the batch.
            }
        }

        return $done;
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $int = is_scalar($id) ? (int) $id : 0;
            if ($int > 0) {
                $out[] = $int;
            }
        }

        return array_values(array_unique($out));
    }

    /** Root-absolute form a rule is keyed by (a full URL is reduced to its path). */
    public static function normalizePath(string $path): string
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
