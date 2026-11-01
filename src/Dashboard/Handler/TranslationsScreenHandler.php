<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/translations — the languages + translation screen:
 * primary/extra locales (add/remove), per-locale translation status, the
 * live translate-job state (running / queued / parked / dead, with the
 * actual error), and the retry control.
 */
final class TranslationsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
        private readonly \Fastsite\Ai\AiClientFactory $ai,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        // Per-locale translation status so the user can SEE that auto-translate
        // is working (or why it isn't) — a common "is this broken?" question.
        $translationStatus = [];
        if ($site->locales !== []) {
            try {
                $db = $this->databases->site($site->handle);
                $totalPublished = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM content WHERE status = 'published' AND type IN ('post', 'page') AND deleted_at IS NULL",
                );
                $byLocale = [];
                foreach ($db->fetchAllAssociative(
                    "SELECT ct.locale AS locale,
                        SUM(CASE WHEN ct.status = 'failed' THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN ct.status = 'pending' THEN 1 ELSE 0 END) AS queued,
                        SUM(CASE WHEN ct.status = 'manual' THEN 1 ELSE 0 END) AS manual,
                        SUM(CASE WHEN ct.status = 'translated' AND ct.source_hash = c.content_hash THEN 1 ELSE 0 END) AS translated,
                        SUM(CASE WHEN ct.status = 'translated' AND ct.source_hash != c.content_hash THEN 1 ELSE 0 END) AS stale
                     FROM content_translations ct JOIN content c ON c.id = ct.content_id
                     WHERE c.status = 'published' AND c.deleted_at IS NULL
                     GROUP BY ct.locale",
                ) as $r) {
                    $byLocale[(string) $r['locale']] = $r;
                }
                // Latest error message per locale, so a "Failed" badge can say
                // WHY (missing/invalid AI key, gateway HTTP error, …). Stale
                // rows record refresh errors too (status stays 'translated'),
                // so match on the error itself, not on status='failed'.
                $errors = [];
                foreach ($db->fetchAllAssociative(
                    "SELECT locale, error FROM content_translations
                     WHERE error IS NOT NULL AND error != ''
                     ORDER BY updated_at DESC",
                ) as $e) {
                    $errors[(string) $e['locale']] ??= (string) $e['error'];
                }
                foreach ($site->locales as $loc) {
                    $r = $byLocale[$loc] ?? [];
                    $done = (int) ($r['translated'] ?? 0) + (int) ($r['stale'] ?? 0) + (int) ($r['manual'] ?? 0)
                        + (int) ($r['failed'] ?? 0) + (int) ($r['queued'] ?? 0);
                    $stale = (int) ($r['stale'] ?? 0);
                    $queued = (int) ($r['queued'] ?? 0);
                    $missing = max(0, $totalPublished - $done);
                    $translationStatus[$loc] = [
                        // A STALE row is both: its old translation is still
                        // published (Live) AND it is outstanding work (In
                        // queue). Showing stale in its own column made Retry
                        // look broken — the button legitimately cannot clear
                        // it, because clearing it would mean marking the row
                        // pending, which unpublishes a page that is fine.
                        'live' => (int) ($r['translated'] ?? 0) + $stale + (int) ($r['manual'] ?? 0),
                        'in_queue' => $stale + $queued + $missing,
                        'translated' => (int) ($r['translated'] ?? 0),
                        'stale' => $stale,
                        'manual' => (int) ($r['manual'] ?? 0),
                        'failed' => (int) ($r['failed'] ?? 0),
                        // 'queued' = reset-by-retry rows waiting for the
                        // worker; 'missing' = never had a row at all. Both
                        // translate on the next sync.
                        'queued' => $queued,
                        'missing' => $missing,
                        'total' => $totalPublished,
                        'error' => $errors[$loc] ?? '',
                    ];
                }
            } catch (\Throwable) {
                // Optional panel — never blank the screen.
            }
        }

        $pendingWork = 0;
        foreach ($translationStatus as $st) {
            $pendingWork += $st['failed'] + $st['stale'] + $st['missing'] + $st['queued'];
        }

        // Live translate-job state. Beyond running/pending this also surfaces
        // the most recent DEAD translate job: before, a job that exhausted
        // its retries showed nothing at all — the user clicked Retry, the
        // job died in the background, and the counts just sat there with no
        // explanation.
        $translationJob = ['running' => 0, 'pending' => 0, 'stalled' => 0, 'retry_at' => '', 'error' => '', 'dead' => false];
        if ($site->locales !== []) {
            try {
                $engine = $this->databases->engine();
                $now = $this->clock->iso();
                // A "running" row whose heartbeat is this old belongs to a
                // worker that was killed mid-run (deploy, OOM) — count it as
                // STALLED, or the badge says "Translating now" for hours
                // while nothing is actually happening. 900s = well past the
                // 600s reclaim threshold, so a healthy long AI call (420s
                // ceiling between heartbeats) can never be mislabeled.
                $stalledCutoff = $this->clock->now()->sub(new \DateInterval('PT900S'))->format('Y-m-d\TH:i:s\Z');
                foreach ($engine->fetchAllAssociative(
                    "SELECT status, run_after, heartbeat_at, error FROM jobs
                     WHERE type = 'translate_content' AND site_handle = ? AND status IN ('pending', 'running')",
                    [$site->handle],
                ) as $j) {
                    if ($j['status'] === 'running') {
                        if ($j['heartbeat_at'] === null || (string) $j['heartbeat_at'] < $stalledCutoff) {
                            ++$translationJob['stalled'];
                        } else {
                            ++$translationJob['running'];
                        }
                        continue;
                    }
                    ++$translationJob['pending'];
                    // A pending chunk parked in retry backoff: show WHEN it
                    // resumes and WHY it failed, or the quiet wait looks
                    // like a hang.
                    if ($j['run_after'] !== null && (string) $j['run_after'] > $now) {
                        $translationJob['retry_at'] = substr((string) $j['run_after'], 11, 5) . ' UTC';
                        $translationJob['error'] = (string) ($j['error'] ?? '');
                    }
                }
                if ($translationJob['running'] === 0 && $translationJob['pending'] === 0) {
                    $last = $engine->fetchAssociative(
                        "SELECT status, error FROM jobs
                         WHERE type = 'translate_content' AND site_handle = ? AND status = 'dead'
                         ORDER BY COALESCE(finished_at, created_at) DESC LIMIT 1",
                        [$site->handle],
                    );
                    if ($last !== false && $pendingWork > 0) {
                        $translationJob['dead'] = true;
                        $translationJob['error'] = (string) ($last['error'] ?? '');
                    }
                }
            } catch (\Throwable) {
                // Optional panel — never blank the screen.
            }
        }

        $translationFailed = array_sum(array_map(static fn (array $s): int => $s['failed'], $translationStatus));

        return $this->dash->render($request, 'translations.html.twig', [
            'title' => $site->name . ' translations — fastsite',
            'site' => $site,
            'translation_status' => $translationStatus,
            'translation_failed' => $translationFailed,
            'translation_pending_work' => $pendingWork,
            'translation_job' => $translationJob,
            'translator_configured' => $this->ai->translatorConfiguredForSite($site),
            // Which provider + model this site will ACTUALLY translate with.
            // Selecting an AI key and seeing nothing change was the whole
            // confusion behind the rate-limit loop; the answer belongs on the
            // screen rather than in a docblock.
            'translator_info' => method_exists($this->ai, 'translatorDescriptionForSite')
                ? $this->ai->translatorDescriptionForSite($site)
                : ['provider' => '', 'model' => '', 'paid_by' => '', 'problem' => ''],
            'locale_add_codes' => \Fastsite\Shared\Locales::codes([$site->primaryLocale, ...$site->locales]),
        ]);
    }
}
