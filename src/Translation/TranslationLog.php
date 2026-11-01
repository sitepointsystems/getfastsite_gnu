<?php

declare(strict_types=1);

namespace Fastsite\Translation;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Writes and reads the per-site record of every translation model call.
 *
 * This exists because translation failures used to be invisible. The app
 * logger writes to a file inside the container, so "the translation keeps
 * parking and retrying" was un-diagnosable from the dashboard — you could see
 * THAT it failed, never WHAT the model said. Every call now leaves a row with
 * the provider's actual answer (or its actual error), and the Translations
 * screen tails it live.
 *
 * Logging must never be able to break a translation: every write is wrapped,
 * because a missing table (a site whose migrations have not run yet) or a
 * locked db is not a reason to fail a page that translated fine.
 */
final class TranslationLog
{
    /**
     * How much of each side of the exchange to keep. Big enough to see a full
     * metadata answer or the shape of a body, small enough that a 700-item
     * backlog does not bloat the site db.
     */
    private const EXCERPT_CHARS = 2000;

    /** Rows kept per site; older ones are trimmed as new ones land. */
    private const KEEP_ROWS = 1000;

    /** Trim roughly every this many writes rather than on every one. */
    private const PRUNE_EVERY = 50;

    private int $writes = 0;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param  'ok'|'failed'|'transport'|'rate_limited'  $status
     */
    public function record(
        string $siteHandle,
        string $locale,
        string $field,
        string $status,
        ?int $contentId = null,
        string $title = '',
        string $format = '',
        string $request = '',
        string $response = '',
        ?string $error = null,
        int $durationMs = 0,
        bool $batched = false,
        string $jobUuid = '',
    ): void {
        try {
            $this->databases->site($siteHandle)->insert('translation_log', [
                'created_at' => $this->clock->iso(),
                'job_uuid' => $jobUuid,
                'content_id' => $contentId,
                'title' => $this->clip($title, 200),
                'locale' => $locale,
                'field' => $field,
                'format' => $format,
                'status' => $status,
                'batched' => $batched ? 1 : 0,
                'duration_ms' => $durationMs,
                'request_chars' => \strlen($request),
                'response_chars' => \strlen($response),
                'request_excerpt' => $this->clip($request, self::EXCERPT_CHARS),
                'response_excerpt' => $this->clip($response, self::EXCERPT_CHARS),
                'error' => $error,
            ]);

            if (++$this->writes % self::PRUNE_EVERY === 0) {
                $this->prune($siteHandle);
            }
        } catch (\Throwable) {
            // Never let bookkeeping fail a translation that worked.
        }
    }

    /**
     * Newest-first rows for the screen. $afterId returns only rows newer than
     * one already displayed, which is what makes the live tail cheap.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $siteHandle, int $limit = 60, int $afterId = 0): array
    {
        try {
            $rows = $this->databases->site($siteHandle)->fetchAllAssociative(
                'SELECT * FROM translation_log WHERE id > ? ORDER BY id DESC LIMIT ?',
                [$afterId, max(1, min(500, $limit))],
            );
        } catch (\Throwable) {
            return []; // table not migrated yet — an empty tail, not an error page
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'at' => substr((string) $r['created_at'], 11, 8),
            'content_id' => $r['content_id'] !== null ? (int) $r['content_id'] : null,
            'title' => (string) $r['title'],
            'locale' => (string) $r['locale'],
            'field' => (string) $r['field'],
            'format' => (string) $r['format'],
            'status' => (string) $r['status'],
            'batched' => (bool) $r['batched'],
            'duration_ms' => (int) $r['duration_ms'],
            'request_chars' => (int) $r['request_chars'],
            'response_chars' => (int) $r['response_chars'],
            'request' => (string) $r['request_excerpt'],
            'response' => (string) $r['response_excerpt'],
            'error' => $r['error'] !== null ? (string) $r['error'] : '',
        ], $rows);
    }

    /** Highest id currently stored — the poll's starting point. */
    public function latestId(string $siteHandle): int
    {
        try {
            return (int) $this->databases->site($siteHandle)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM translation_log');
        } catch (\Throwable) {
            return 0;
        }
    }

    public function clear(string $siteHandle): int
    {
        try {
            return (int) $this->databases->site($siteHandle)->executeStatement('DELETE FROM translation_log');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function prune(string $siteHandle): void
    {
        $this->databases->site($siteHandle)->executeStatement(
            'DELETE FROM translation_log WHERE id <= (SELECT MAX(id) FROM translation_log) - ?',
            [self::KEEP_ROWS],
        );
    }

    private function clip(string $text, int $max): string
    {
        $text = trim($text);

        return \strlen($text) <= $max ? $text : substr($text, 0, $max) . '…';
    }
}
