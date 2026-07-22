<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * The queue worker (bin/fastsite queue:work — exactly ONE process in v1).
 *
 * Concurrency contract (worker and scheduler run as separate processes):
 * every terminal-state UPDATE is guarded by the expected current status,
 * and side effects (chain enqueue, retry delay, logs) only fire when the
 * guarded UPDATE actually claimed the transition. The sweep additionally
 * guards on the heartbeat it observed, so a worker that ticks between the
 * sweep's SELECT and UPDATE is never reclaimed.
 */
final class JobRunner
{
    /**
     * Must exceed the worst un-heartbeated phase with margin: git
     * subprocesses may legally take up to 300s each. Aligned with the
     * 900s SiteLock TTL.
     */
    private const STALE_AFTER_SECONDS = 600;
    private const STALE_SWEEP_EVERY_SECONDS = 300;
    private const BASE_BACKOFF_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 3600;
    private const LOCK_RETRY_SECONDS = 30;
    private const ERROR_PAUSE_SECONDS = 5;

    private int $lastSweep = 0;
    private bool $shouldStop = false;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly JobHandlerRegistry $handlers,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Worker loop. Never dies on transient Redis/DB errors (logs + pauses);
     * SIGTERM/SIGINT drain gracefully where pcntl exists (the container).
     */
    public function work(?int $maxRuntimeSeconds = null, bool $once = false): int
    {
        $this->installSignalHandlers();
        $started = time();
        $processed = 0;

        while (!$this->shouldStop) {
            try {
                $this->sweepStaleJobs();
                $this->queue->promoteDelayed();

                $uuid = $this->queue->pop(5);
                if ($uuid !== null && $this->execute($uuid)) {
                    ++$processed;
                    if ($once) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Transient Redis/SQLite failure must not kill the worker —
                // degrade to idle-with-retry (scheduler tick pattern).
                $this->logger->error('Worker iteration failed', ['error' => $e->getMessage()]);
                $uuid = null;
                sleep(self::ERROR_PAUSE_SECONDS);
            }

            if ($maxRuntimeSeconds !== null && (time() - $started) >= $maxRuntimeSeconds) {
                break;
            }
            if ($once && $uuid === null) {
                break; // queue drained
            }
        }

        if ($this->shouldStop) {
            $this->logger->info('Worker stopping on signal — in-flight job finished.');
        }

        return $processed;
    }

    /**
     * Reclaims running jobs whose heartbeat is stale (crashed worker).
     * Public so schedule:run sweeps too — the worker being dead is exactly
     * when the sweep matters most.
     */
    public function sweepStaleJobs(bool $force = false): int
    {
        if (!$force && (time() - $this->lastSweep) < self::STALE_SWEEP_EVERY_SECONDS) {
            return 0;
        }
        $this->lastSweep = time();

        $cutoff = $this->clock->now()
            ->sub(new \DateInterval('PT' . self::STALE_AFTER_SECONDS . 'S'))
            ->format('Y-m-d\TH:i:s\Z');

        $rows = $this->databases->engine()->fetchAllAssociative(
            "SELECT * FROM jobs WHERE status = 'running' AND heartbeat_at < ?",
            [$cutoff],
        );

        $reclaimed = 0;
        foreach ($rows as $row) {
            $job = Job::fromRow($row);
            // Guard on the observed heartbeat: if the worker ticked (or
            // finished) since our SELECT, this reclaim must be a no-op.
            if ($this->fail($job, 'Reclaimed after a stale heartbeat (worker slow or unresponsive).', (string) $row['heartbeat_at'])) {
                $this->logger->warning('Reclaimed stale job', ['uuid' => $job->uuid, 'type' => $job->type, 'attempts' => $job->attempts]);
                ++$reclaimed;
            }
        }

        return $reclaimed;
    }

    private function execute(string $uuid): bool
    {
        $db = $this->databases->engine();

        // Claim atomically: only a pending, DUE row may start running —
        // duplicate list entries must not let a job skip its backoff.
        $now = $this->clock->iso();
        $reservedBy = gethostname() . ':' . getmypid();
        $claimed = $db->executeStatement(
            "UPDATE jobs
             SET status = 'running', attempts = attempts + 1, reserved_by = ?,
                 started_at = COALESCE(started_at, ?), heartbeat_at = ?
             WHERE uuid = ? AND status = 'pending' AND (run_after IS NULL OR run_after <= ?)",
            [$reservedBy, $now, $now, $uuid, $now],
        );
        if ($claimed !== 1) {
            return false; // duplicate pop, no longer pending, or not yet due
        }

        $row = $db->fetchAssociative('SELECT * FROM jobs WHERE uuid = ?', [$uuid]);
        \assert($row !== false);
        $job = Job::fromRow($row);

        $context = new JobContext(function () use ($db, $uuid): void {
            $db->executeStatement(
                "UPDATE jobs SET heartbeat_at = ? WHERE uuid = ? AND status = 'running'",
                [$this->clock->iso(), $uuid],
            );
        });

        try {
            $this->handlers->for($job->type)->run($job, $context);
        } catch (LockBusyException) {
            $retryAt = $this->clock->now()->add(new \DateInterval('PT' . self::LOCK_RETRY_SECONDS . 'S'));
            $unclaimed = $db->executeStatement(
                "UPDATE jobs SET status = 'pending', attempts = attempts - 1, run_after = ?
                 WHERE uuid = ? AND status = 'running'",
                [$retryAt->format('Y-m-d\TH:i:s\Z'), $uuid],
            );
            if ($unclaimed === 1) {
                $this->queue->delay($uuid, $retryAt);
                $this->logger->info('Job requeued (site locked)', ['uuid' => $uuid, 'type' => $job->type]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->fail($job, $e->getMessage());

            return true;
        }

        $this->complete($job);

        return true;
    }

    /**
     * Marks done and enqueues the next chain step in ONE engine.db
     * transaction — a crash between "done" and the chain insert can never
     * lose the chain. The Redis push happens after commit; if THAT is lost,
     * the scheduler's orphan rescue re-pushes the pending row.
     */
    private function complete(Job $job): void
    {
        $db = $this->databases->engine();

        $db->beginTransaction();
        try {
            $done = $db->executeStatement(
                "UPDATE jobs SET status = 'done', finished_at = ?, error = NULL
                 WHERE uuid = ? AND status = 'running'",
                [$this->clock->iso(), $job->uuid],
            );

            if ($done !== 1) {
                // A sweep reclaimed this job while we finished — its retry
                // will redo the work and enqueue the chain itself.
                $db->rollBack();
                $this->logger->warning('Completion lost to a concurrent reclaim — retry will supersede', ['uuid' => $job->uuid]);

                return;
            }

            $nextUuid = null;
            if ($job->chain !== null && $job->chain !== []) {
                $next = $job->chain[0];
                $nextUuid = $this->queue->createRow(
                    type: (string) ($next['type'] ?? ''),
                    siteHandle: $job->siteHandle,
                    payload: (array) ($next['payload'] ?? []) + $job->payload,
                    queue: (string) ($next['queue'] ?? $job->queue),
                    dedupeKey: null,
                    chain: \array_slice($job->chain, 1),
                    runAfter: null,
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->logger->info('Job done', ['uuid' => $job->uuid, 'type' => $job->type, 'site' => $job->siteHandle]);

        if ($nextUuid !== null) {
            $this->queue->pushExisting($nextUuid);
        }
    }

    /**
     * Failure path shared by exceptions and the stale sweep: backoff-retry
     * until max_attempts, then dead-letter. Returns whether THIS call won
     * the state transition (guards against worker/sweep races).
     */
    private function fail(Job $job, string $error, ?string $expectedHeartbeat = null): bool
    {
        $db = $this->databases->engine();
        $guard = "status = 'running'" . ($expectedHeartbeat !== null ? ' AND heartbeat_at = ?' : '');
        $guardParams = $expectedHeartbeat !== null ? [$expectedHeartbeat] : [];

        if ($job->attempts >= $job->maxAttempts) {
            $updated = $db->executeStatement(
                "UPDATE jobs SET status = 'dead', finished_at = ?, error = ? WHERE uuid = ? AND {$guard}",
                [$this->clock->iso(), $error, $job->uuid, ...$guardParams],
            );
            if ($updated === 1) {
                $this->logger->error('Job dead-lettered', ['uuid' => $job->uuid, 'type' => $job->type, 'error' => $error]);
            }

            return $updated === 1;
        }

        $backoff = min(self::BASE_BACKOFF_SECONDS * (2 ** max(0, $job->attempts - 1)), self::MAX_BACKOFF_SECONDS)
            + random_int(0, 15);
        $runAfter = $this->clock->now()->add(new \DateInterval('PT' . $backoff . 'S'));

        $updated = $db->executeStatement(
            "UPDATE jobs SET status = 'pending', run_after = ?, error = ? WHERE uuid = ? AND {$guard}",
            [$runAfter->format('Y-m-d\TH:i:s\Z'), $error, $job->uuid, ...$guardParams],
        );
        if ($updated === 1) {
            $this->queue->delay($job->uuid, $runAfter);
            $this->logger->warning('Job failed — retrying', [
                'uuid' => $job->uuid, 'type' => $job->type, 'attempt' => $job->attempts,
                'retry_in' => $backoff, 'error' => $error,
            ]);
        }

        return $updated === 1;
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_async_signals')) {
            return; // Windows dev — supervisor/pcntl only exist in the container
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->shouldStop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
