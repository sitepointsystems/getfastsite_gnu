<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\SiteRepository;
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

    private int $lastRescue = 0;
    private ?string $lastNightlyBackupDate = null;

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly JobQueue $queue,
        private readonly JobRunner $runner,
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
                // The loop never dies — log and keep ticking (conzent pattern).
                $this->logger->error('Scheduler tick failed', ['error' => $e->getMessage()]);
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
     * content is newer than the last successful build's START, enqueue a
     * deduped build. Self-heals every failure mode around "flipped in
     * site.db but the enqueue was lost" — including content published
     * while a build was already running (its snapshot predates the flip).
     */
    private function reconcileUnbuiltContent(): void
    {
        foreach ($this->sites->all() as $site) {
            try {
                $newest = $this->databases->site($site->handle)->fetchOne(
                    "SELECT MAX(updated_at) FROM content WHERE status = 'published' AND deleted_at IS NULL",
                );
                if ($newest === false || $newest === null) {
                    continue;
                }

                $lastBuildStart = $this->databases->engine()->fetchOne(
                    "SELECT started_at FROM builds WHERE site_handle = ? AND status = 'done'
                     ORDER BY id DESC LIMIT 1",
                    [$site->handle],
                );

                if ($lastBuildStart !== false && (string) $newest <= (string) $lastBuildStart) {
                    continue; // deployed output already covers everything
                }

                if ($site->locales !== []) {
                    // Multilingual: refresh translations first, which then
                    // chains the build+publish itself.
                    $this->queue->enqueue(
                        type: 'translate_content',
                        siteHandle: $site->handle,
                        payload: ['trigger' => 'schedule'],
                        queue: 'default',
                        dedupeKey: 'translate:' . $site->handle,
                    );
                } else {
                    $this->queue->enqueue(
                        type: 'build_full',
                        siteHandle: $site->handle,
                        payload: ['trigger' => 'schedule'],
                        queue: 'high',
                        dedupeKey: 'build:' . $site->handle,
                        chain: [['type' => 'publish_git', 'payload' => ['message' => 'publish: content changes']]],
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error('reconcileUnbuiltContent failed for site', ['site' => $site->handle, 'error' => $e->getMessage()]);
            }
        }
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
