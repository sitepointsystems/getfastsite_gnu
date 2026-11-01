<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Translation\Service\TranslationService;
use Psr\Log\LoggerInterface;

/**
 * The minute loop (bin/fastsite schedule:run): promotes due scheduled
 * content to published (+ enqueues its build), promotes delayed jobs,
 * sweeps stale jobs even when the worker itself is the casualty, and
 * rescues pending jobs that vanished from Redis.
 */
final class Scheduler
{
    private const RESCUE_EVERY_SECONDS = 600;
    private const RESCUE_MIN_AGE_SECONDS = 600;
    private const NIGHTLY_BACKUP_HOUR = 2;
    private const PUBLISH_SNAPSHOT_MIN_INTERVAL_SECONDS = 600;

    /**
     * Dead-pipeline revival cooldown: when a site's pipeline recently
     * dead-lettered, the reconciler waits before re-triggering — escalating
     * with each dead job in the last 24h (30min, 1h, 2h, 4h, capped at 6h).
     * Without this the level-based trigger re-spawned a doomed pipeline at
     * tick speed; with it, a broken gateway/key costs one short probe every
     * few hours and the site heals AUTOMATICALLY the moment the cause is
     * fixed. User-initiated publishes (dashboard, API, webhook, Retry) are
     * never gated — this dampens only the scheduler's own safety net.
     */
    private const REVIVAL_BASE_COOLDOWN_SECONDS = 1800;
    private const REVIVAL_MAX_COOLDOWN_SECONDS = 21600;

    private int $lastRescue = 0;
    private ?string $lastNightlyBackupDate = null;

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly JobQueue $queue,
        private readonly JobRunner $runner,
        private readonly PublishTrigger $publishTrigger,
        private readonly TranslationService $translations,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(bool $once = false, int $intervalSeconds = 60): void
    {
        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                // The loop never dies — log and keep ticking (house pattern).
                $this->logger->error('Scheduler tick failed', ['error' => $e->getMessage()]);
            } finally {
                // Same reason as JobRunner's per-job release: a restore in a
                // worker swaps the site.db FILE, and this process holds its
                // own connection cache for the life of the loop. The content
                // reconciler reads EVERY site every tick, so a stale handle
                // here does not merely serve old rows — it re-triggers builds
                // from the pre-restore ones, which looks like self-healing.
                // In the finally rather than after the catch: a throwing tick
                // is exactly when the handles are most likely to be wrong.
                try {
                    $this->databases->closeSites();
                } catch (\Throwable $e) {
                    $this->logger->warning('Could not release site connections after a tick', ['error' => $e->getMessage()]);
                }
            }

            if ($once) {
                return;
            }
            sleep($intervalSeconds);
        }
    }

    public function tick(): void
    {
        $this->publishDueContent();
        $this->reconcileUnbuiltContent();
        $this->reconcileStuckDeliveries();
        $this->queue->promoteDelayed();
        $this->runner->sweepStaleJobs(force: true);
        $this->rescueOrphanedPending();
        $this->runNightlyBackups();
        $this->snapshotAfterPublish();
    }

    /**
     * Daily engine + every-site full backup + prune, due from 02:00 UTC.
     * LEVEL-based like the other reconcilers: the source of record is the
     * backups table (has a schedule-triggered engine snapshot been RECORDED
     * today?) plus an in-flight jobs check — so a scheduler that was down
     * over the 02:00 window still catches up later, and a same-day restart
     * never duplicates a completed run (the in-memory date is only a
     * fast-path; job dedupe keys are pending-only and can't carry this).
     */
    private function runNightlyBackups(): void
    {
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        if ((int) $now->format('H') < self::NIGHTLY_BACKUP_HOUR || $this->lastNightlyBackupDate === $today) {
            return;
        }

        try {
            $engine = $this->databases->engine();
            $doneToday = $engine->fetchOne(
                "SELECT 1 FROM backups WHERE scope = 'engine' AND kind = 'db'
                   AND trigger_source = 'schedule' AND status = 'done' AND created_at >= ? LIMIT 1",
                [$today . 'T00:00:00Z'],
            );
            if ($doneToday !== false) {
                $this->lastNightlyBackupDate = $today;

                return;
            }
            // The recorded row only appears when backup_engine COMPLETES —
            // bridge the running window (dedupe keys cover pending only).
            $inFlight = $engine->fetchOne(
                "SELECT 1 FROM jobs WHERE type = 'backup_engine' AND status IN ('pending', 'running') LIMIT 1",
            );
            if ($inFlight !== false) {
                return;
            }

            $this->queue->enqueue(type: 'backup_engine', payload: ['trigger' => 'schedule'], queue: 'low', dedupeKey: 'backup:engine');
            foreach ($this->sites->all() as $site) {
                $this->queue->enqueue(
                    type: 'backup_site',
                    siteHandle: $site->handle,
                    payload: ['trigger' => 'schedule', 'kind' => 'full'],
                    queue: 'low',
                    dedupeKey: 'backup:full:' . $site->handle,
                );
            }
            $this->queue->enqueue(type: 'prune_backups', payload: ['trigger' => 'schedule'], queue: 'low', dedupeKey: 'prune:backups');

            // Held spam ages out here rather than on its own timer. It is the
            // same once-a-day, level-based shape, and riding this block gets
            // the catch-up-after-downtime and no-duplicate-after-restart
            // behaviour for free instead of duplicating the day-gate. One job
            // sweeps every site, since each carries its own retention window.
            $this->queue->enqueue(type: 'purge_spam', payload: ['trigger' => 'schedule'], queue: 'low', dedupeKey: 'purge:spam');

            $this->lastNightlyBackupDate = $today;
            $this->logger->info('Nightly backups enqueued', ['date' => $today]);
        } catch (\Throwable $e) {
            $this->logger->error('runNightlyBackups failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Level-based on-publish DB snapshot: whenever a site's last completed
     * build is newer than its last DB backup — and that backup is at least
     * 10 minutes old — enqueue a db snapshot. Dedupe + the age gate keep a
     * burst of publishes to one snapshot.
     */
    private function snapshotAfterPublish(): void
    {
        $tooSoon = $this->clock->now()
            ->sub(new \DateInterval('PT' . self::PUBLISH_SNAPSHOT_MIN_INTERVAL_SECONDS . 'S'))
            ->format('Y-m-d\TH:i:s\Z');
        $engine = $this->databases->engine();

        foreach ($this->sites->all() as $site) {
            try {
                $lastBuild = $engine->fetchOne(
                    "SELECT finished_at FROM builds WHERE site_handle = ? AND status = 'done' AND finished_at IS NOT NULL
                     ORDER BY id DESC LIMIT 1",
                    [$site->handle],
                );
                if ($lastBuild === false || $lastBuild === null) {
                    continue;
                }
                $lastBackup = $engine->fetchOne(
                    "SELECT created_at FROM backups WHERE scope = 'site' AND kind = 'db' AND site_handle = ? AND status = 'done'
                     ORDER BY id DESC LIMIT 1",
                    [$site->handle],
                );
                if ($lastBackup !== false && $lastBackup !== null) {
                    if ((string) $lastBuild <= (string) $lastBackup) {
                        continue; // already snapshotted since the last build
                    }
                    if ((string) $lastBackup > $tooSoon) {
                        continue; // within the 10-minute rate limit
                    }
                }

                // The dedupe key only coalesces PENDING twins; a snapshot
                // that is currently RUNNING hasn't written its backups row
                // yet, so without this check every tick during a slow upload
                // would enqueue another one.
                $inFlight = $engine->fetchOne(
                    "SELECT 1 FROM jobs WHERE dedupe_key = ? AND status IN ('pending', 'running') LIMIT 1",
                    ['backup:db:' . $site->handle],
                );
                if ($inFlight !== false) {
                    continue;
                }

                $this->queue->enqueue(
                    type: 'backup_site',
                    siteHandle: $site->handle,
                    payload: ['trigger' => 'publish', 'kind' => 'db'],
                    queue: 'low',
                    dedupeKey: 'backup:db:' . $site->handle,
                );
                // Enforce the retention cap continuously, not just nightly, so
                // a burst of publishes can't pile up snapshots for a whole day
                // before the 02:00 prune trims to RETAIN_PER_GROUP. Deduped, so
                // this coalesces to at most one pending prune.
                $this->queue->enqueue(
                    type: 'prune_backups',
                    payload: ['trigger' => 'publish'],
                    queue: 'low',
                    dedupeKey: 'prune:backups',
                );
            } catch (\Throwable $e) {
                $this->logger->error('snapshotAfterPublish failed for site', ['site' => $site->handle, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * A webhook delivery recorded but never enqueued (the process_webhook
     * enqueue threw after the delivery row committed) would sit forever in
     * 'received'. Age-gated re-enqueue rescues it. Safe: process_webhook is
     * idempotent (skips already-processed; mapper upserts on source_ref).
     */
    private function reconcileStuckDeliveries(): void
    {
        $cutoff = $this->clock->now()
            ->sub(new \DateInterval('PT' . self::RESCUE_MIN_AGE_SECONDS . 'S'))
            ->format('Y-m-d\TH:i:s\Z');

        try {
            $rows = $this->databases->engine()->fetchAllAssociative(
                "SELECT d.id, i.site_handle FROM webhook_deliveries d
                 JOIN webhook_integrations i ON i.id = d.integration_id
                 WHERE d.status = 'received' AND d.created_at < ? LIMIT 100",
                [$cutoff],
            );
            foreach ($rows as $row) {
                $this->queue->enqueue(
                    type: 'process_webhook',
                    siteHandle: (string) $row['site_handle'],
                    payload: ['delivery_id' => (int) $row['id']],
                    queue: 'default',
                );
                $this->logger->warning('Re-enqueued a stuck webhook delivery', ['delivery' => (int) $row['id']]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('reconcileStuckDeliveries failed', ['error' => $e->getMessage()]);
        }
    }

    private function publishDueContent(): void
    {
        $now = $this->clock->iso();

        foreach ($this->sites->all() as $site) {
            try {
                $db = $this->databases->site($site->handle);
                $due = $db->fetchAllAssociative(
                    "SELECT id, title, scheduled_at FROM content
                     WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?
                       AND deleted_at IS NULL",
                    [$now],
                );

                foreach ($due as $row) {
                    // published_at = the scheduled date the author picked (that IS
                    // the publish date), not the moment the scheduler happened to run.
                    $publishedAt = (string) ($row['scheduled_at'] ?? '') ?: $now;
                    $db->executeStatement(
                        "UPDATE content SET status = 'published', published_at = ?, updated_at = ? WHERE id = ?",
                        [$publishedAt, $now, (int) $row['id']],
                    );
                    $this->logger->info('Scheduled content published', ['site' => $site->handle, 'id' => (int) $row['id'], 'title' => (string) $row['title']]);
                }
            } catch (\Throwable $e) {
                // One broken site must not stall the others.
                $this->logger->error('publishDueContent failed for site', ['site' => $site->handle, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * LEVEL-based deploy trigger (not edge-based): whenever published
     * content is newer than the last successful build's START — or a
     * multilingual site has pending TRANSLATION work with nothing driving
     * it (a dead translate job used to strand missing locales forever) —
     * enqueue a deduped pipeline run. Self-heals every failure mode around
     * "flipped in site.db but the enqueue was lost", including content
     * published while a build was already running.
     */
    private function reconcileUnbuiltContent(): void
    {
        // In-flight = pending, or running with a LIVE heartbeat. The dedupe
        // key only coalesces PENDING twins, so without this check every
        // 60s tick spawned a NEW translate/build job while the previous one
        // was still running — and when a worker kept getting killed mid-run
        // (deploy, OOM), that manufactured an unbounded pile of zombie
        // "running" rows, one per tick. A running row whose heartbeat has
        // gone stale is a zombie, NOT in-flight — it must never block the
        // reconciler (the sweep/Retry clean it up independently).
        $staleCutoff = $this->clock->now()->sub(new \DateInterval('PT900S'))->format('Y-m-d\TH:i:s\Z');

        foreach ($this->sites->all() as $site) {
            try {
                // TRASHED ROWS COUNT, and leaving them out was a real bug:
                // `softDelete` bumps `updated_at` and leaves `status` alone,
                // so a deletion raises this level exactly as an edit does —
                // but `deleted_at IS NULL` hid the one row that had just
                // changed, and a deletion is the only content change that
                // LOWERS the maximum of what is left. So nothing was ever
                // queued: a page deleted over MCP or the REST API stayed live
                // indefinitely, with the row in the trash on every screen and
                // no build to take it off the CDN. (Measured: 7+ minutes and
                // counting on a real site, with the content row already gone.)
                // Drafts stay out via the status filter — a draft never had a
                // page, so deleting one changes no output.
                $newest = $this->databases->site($site->handle)->fetchOne(
                    "SELECT MAX(updated_at) FROM content WHERE status = 'published'",
                );
                if ($newest === false || $newest === null) {
                    continue;
                }

                $lastBuildStart = $this->databases->engine()->fetchOne(
                    "SELECT started_at FROM builds WHERE site_handle = ? AND status = 'done'
                     ORDER BY id DESC LIMIT 1",
                    [$site->handle],
                );

                $unbuilt = $lastBuildStart === false || (string) $newest > (string) $lastBuildStart;

                // Translation gap: the built output covers the content, but
                // locales are missing/stale and no job is driving them (the
                // one that was died). Without this branch, work created by
                // add-language or a failed run only resumed on the next
                // content edit or a manual Retry.
                $translationGap = !$unbuilt && $site->locales !== [] && $this->translations->hasPendingWork($site);
                if (!$unbuilt && !$translationGap) {
                    continue; // deployed output already covers everything
                }

                $inFlight = $this->databases->engine()->fetchOne(
                    "SELECT 1 FROM jobs
                     WHERE site_handle = ? AND type IN ('translate_content', 'build_full', 'publish_git', 'publish_ftp')
                       AND (status = 'pending' OR (status = 'running' AND heartbeat_at >= ?))
                     LIMIT 1",
                    [$site->handle, $staleCutoff],
                );
                if ($inFlight !== false) {
                    continue; // the pipeline is already on it — re-check next tick
                }

                if ($this->inRevivalCooldown($site->handle)) {
                    continue; // recently dead-lettered — wait out the escalating cooldown
                }

                // PublishTrigger decides the route: translate first only when
                // translation work actually exists, else straight to build.
                $this->publishTrigger->trigger($site, 'publish: content changes', 'schedule');
            } catch (\Throwable $e) {
                $this->logger->error('reconcileUnbuiltContent failed for site', ['site' => $site->handle, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Whether the site's pipeline dead-lettered recently enough that the
     * reconciler should hold off. Level-based off the jobs table: N dead
     * pipeline jobs in the last 24h → wait min(6h, 30min·2^(N-1)) after the
     * newest one before auto-reviving. No dead jobs → no cooldown.
     */
    private function inRevivalCooldown(string $handle): bool
    {
        $windowStart = $this->clock->now()->sub(new \DateInterval('PT86400S'))->format('Y-m-d\TH:i:s\Z');
        $row = $this->databases->engine()->fetchAssociative(
            "SELECT COUNT(*) AS n, MAX(finished_at) AS last FROM jobs
             WHERE site_handle = ? AND type IN ('translate_content', 'build_full', 'publish_git', 'publish_ftp')
               AND status = 'dead' AND finished_at > ?",
            [$handle, $windowStart],
        );
        if ($row === false || (int) $row['n'] === 0) {
            return false;
        }
        $deadCount = (int) $row['n'];

        $cooldown = min(self::REVIVAL_MAX_COOLDOWN_SECONDS, self::REVIVAL_BASE_COOLDOWN_SECONDS * (2 ** min(4, $deadCount - 1)));
        $reviveAt = (new \DateTimeImmutable((string) $row['last']))->add(new \DateInterval('PT' . $cooldown . 'S'));

        return $this->clock->now() < $reviveAt;
    }

    /**
     * A pending job whose uuid fell out of Redis (flush, crash between
     * ZREM and LPUSH) would wait forever — re-push stale pending jobs.
     * Duplicate list entries are harmless: claiming is atomic on the row.
     */
    private function rescueOrphanedPending(): void
    {
        if ((time() - $this->lastRescue) < self::RESCUE_EVERY_SECONDS) {
            return;
        }
        $this->lastRescue = time();

        $cutoff = $this->clock->now()
            ->sub(new \DateInterval('PT' . self::RESCUE_MIN_AGE_SECONDS . 'S'))
            ->format('Y-m-d\TH:i:s\Z');

        $stuck = $this->databases->engine()->fetchAllAssociative(
            "SELECT uuid, queue, run_after FROM jobs
             WHERE status = 'pending' AND created_at < ?
               AND (run_after IS NULL OR run_after <= ?)",
            [$cutoff, $this->clock->iso()],
        );

        foreach ($stuck as $row) {
            $this->queue->delay((string) $row['uuid'], $this->clock->now());
        }
        if ($stuck !== []) {
            $this->queue->promoteDelayed();
            $this->logger->warning('Rescued pending jobs back onto Redis', ['count' => \count($stuck)]);
        }
    }
}
