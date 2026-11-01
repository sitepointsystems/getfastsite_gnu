<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Predis\Client;
use Ramsey\Uuid\Uuid;

/**
 * Job intake + Redis wiring. State of record lives in engine.db `jobs`;
 * Redis only carries uuids (lists per queue + a delayed ZSET), so a Redis
 * flush loses nothing that requeueFromDatabase() cannot restore.
 */
final class JobQueue
{
    /** Order = BRPOP priority for a worker serving every queue (dev). */
    public const QUEUES = ['high', 'build', 'default', 'media', 'translate', 'low'];

    /**
     * Type→lane routing, applied centrally in createRow() so direct enqueues
     * AND chain steps land on the lane their dedicated worker serves —
     * call sites cannot accidentally put a build on a shared queue.
     * Unmapped types keep the caller's queue (high/default/low).
     */
    private const TYPE_QUEUES = [
        'build_full' => 'build',
        'publish_git' => 'build',
        'publish_ftp' => 'build',
        'translate_content' => 'translate',
        'optimize_media' => 'media',
        // A lead notification is the most time-sensitive thing the engine
        // sends, and it is tiny — it goes at the front of the general worker's
        // priority order rather than queueing behind housekeeping.
        'send_form_email' => 'high',
    ];

    private const KEY_PREFIX = 'fastsite:queue:';
    private const DELAYED_KEY = 'fastsite:queue:delayed';
    private const WORKER_KEY_PREFIX = 'fastsite:worker:';
    private const WORKER_TTL_SECONDS = 90;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Client $redis,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Enqueues a job. With a dedupe key, an already PENDING twin wins and
     * its uuid is returned (bursts coalesce). A RUNNING twin does NOT
     * absorb the enqueue — it already snapshotted its input; the new job
     * serializes behind it via the per-site lock.
     *
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $chain steps after this one: [{type, payload?, queue?}, …]
     */
    public function enqueue(
        string $type,
        ?string $siteHandle = null,
        array $payload = [],
        string $queue = 'default',
        ?string $dedupeKey = null,
        array $chain = [],
        ?\DateTimeImmutable $runAfter = null,
    ): string {
        $uuid = $this->createRow($type, $siteHandle, $payload, $queue, $dedupeKey, $chain, $runAfter);
        $this->pushExisting($uuid);

        return $uuid;
    }

    /**
     * Inserts the jobs row WITHOUT touching Redis — callable inside an
     * engine.db transaction (chain durability). Returns the new job's uuid,
     * or the pending twin's uuid on dedupe.
     *
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $chain
     */
    public function createRow(
        string $type,
        ?string $siteHandle,
        array $payload,
        string $queue,
        ?string $dedupeKey,
        array $chain,
        ?\DateTimeImmutable $runAfter,
    ): string {
        $queue = self::TYPE_QUEUES[$type] ?? $queue;
        if (!\in_array($queue, self::QUEUES, true)) {
            throw new \InvalidArgumentException('Unknown queue: ' . $queue);
        }

        $db = $this->databases->engine();

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $uuid = Uuid::uuid4()->toString();
            try {
                $db->insert('jobs', [
                    'uuid' => $uuid,
                    'type' => $type,
                    'queue' => $queue,
                    'site_handle' => $siteHandle,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'dedupe_key' => $dedupeKey,
                    'chain' => $chain !== [] ? json_encode(array_values($chain), JSON_THROW_ON_ERROR) : null,
                    'run_after' => $runAfter?->format('Y-m-d\TH:i:s\Z'),
                    'created_at' => $this->clock->iso(),
                ]);

                return $uuid;
            } catch (UniqueConstraintViolationException) {
                $existing = $db->fetchOne(
                    "SELECT uuid FROM jobs WHERE dedupe_key = ? AND status = 'pending'",
                    [$dedupeKey],
                );
                if ($existing !== false) {
                    return (string) $existing;
                }
                // Twin vanished between the violation and the lookup —
                // the index slot is free again, retry the insert once.
            }
        }

        throw new \RuntimeException('Job dedupe race persisted across retry — enqueue again.');
    }

    /**
     * Puts an existing PENDING job onto Redis (list, or delayed ZSET when
     * run_after is in the future). No-op for non-pending jobs.
     */
    public function pushExisting(string $uuid): void
    {
        $row = $this->databases->engine()->fetchAssociative(
            "SELECT queue, run_after FROM jobs WHERE uuid = ? AND status = 'pending'",
            [$uuid],
        );
        if ($row === false) {
            return;
        }

        if ($row['run_after'] !== null && (string) $row['run_after'] > $this->clock->iso()) {
            $ts = (new \DateTimeImmutable((string) $row['run_after']))->getTimestamp();
            $this->redis->zadd(self::DELAYED_KEY, [$uuid => $ts]);
        } else {
            $this->redis->lpush(self::KEY_PREFIX . (string) $row['queue'], [$uuid]);
        }
    }

    /**
     * Forces a parked PENDING job due NOW: clears any retry backoff and puts
     * it straight onto its queue list. The user-initiated "retry" paths call
     * this after enqueue() — a dedupe-absorbed enqueue returns the failed
     * twin's uuid, and without the kick the click silently waits out a
     * backoff of up to an hour. Safe against races: duplicate list entries
     * are harmless (claiming is guarded) and the stale delayed-ZSET member
     * promotes to a no-op once the job is no longer pending.
     */
    /**
     * Merges fields into a PENDING job's payload.
     *
     * The case this exists for: a dedupe-absorbed enqueue returns the twin that
     * was already queued, whose payload was written before this request. Without
     * this, a flag the caller set (force) would be silently dropped on exactly
     * the path where two people asked for the same thing at once.
     *
     * @param array<string, mixed> $fields
     */
    public function mergePayload(string $uuid, array $fields): void
    {
        $db = $this->databases->engine();
        $current = $db->fetchOne("SELECT payload FROM jobs WHERE uuid = ? AND status = 'pending'", [$uuid]);
        if ($current === false) {
            return; // running, done, or dead — its input is already snapshotted
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $current, true) ?: [];
        $db->executeStatement(
            "UPDATE jobs SET payload = ? WHERE uuid = ? AND status = 'pending'",
            [json_encode(array_replace($payload, $fields), JSON_THROW_ON_ERROR), $uuid],
        );
    }

    public function promoteNow(string $uuid): void
    {
        $db = $this->databases->engine();
        $queue = $db->fetchOne("SELECT queue FROM jobs WHERE uuid = ? AND status = 'pending'", [$uuid]);
        if ($queue === false) {
            return; // running, done, or dead — nothing to kick
        }
        $db->executeStatement("UPDATE jobs SET run_after = NULL WHERE uuid = ? AND status = 'pending'", [$uuid]);
        $this->redis->zrem(self::DELAYED_KEY, $uuid);
        $this->redis->lpush(self::KEY_PREFIX . (string) $queue, [$uuid]);
    }

    /**
     * Moves due members of the delayed ZSET onto their queue lists.
     */
    public function promoteDelayed(): int
    {
        $due = $this->redis->zrangebyscore(self::DELAYED_KEY, '-inf', (string) $this->clock->now()->getTimestamp(), [
            'LIMIT' => ['OFFSET' => 0, 'COUNT' => 100],
        ]);

        $promoted = 0;
        foreach ($due as $uuid) {
            // Only the remover promotes — safe with concurrent promoters.
            if ((int) $this->redis->zrem(self::DELAYED_KEY, $uuid) !== 1) {
                continue;
            }
            $queue = $this->databases->engine()->fetchOne(
                "SELECT queue FROM jobs WHERE uuid = ? AND status = 'pending'",
                [$uuid],
            );
            if ($queue === false) {
                continue; // job gone or no longer pending — drop silently
            }
            $this->redis->lpush(self::KEY_PREFIX . (string) $queue, [$uuid]);
            ++$promoted;
        }

        return $promoted;
    }

    /**
     * Blocking pop across queues in the given order (all of them, priority
     * order, when none given). Returns a job uuid or null. An unknown queue
     * name throws — a supervisord --queues typo must fail loudly, not brew
     * a worker that silently serves nothing.
     *
     * @param list<string>|null $queues
     */
    public function pop(int $timeoutSeconds = 5, ?array $queues = null): ?string
    {
        $queues ??= self::QUEUES;
        foreach ($queues as $queue) {
            if (!\in_array($queue, self::QUEUES, true)) {
                throw new \InvalidArgumentException('Unknown queue: ' . $queue);
            }
        }

        $keys = array_map(static fn (string $q): string => self::KEY_PREFIX . $q, $queues);
        $result = $this->redis->brpop($keys, $timeoutSeconds);

        return $result !== null ? (string) $result[1] : null;
    }

    /**
     * Liveness beacon: each worker refreshes a short-TTL key per queue it
     * serves on every loop iteration, so doctor can tell "backlog because
     * busy" from "backlog because the lane's worker is down".
     *
     * @param list<string> $queues
     */
    public function markWorkerAlive(array $queues): void
    {
        foreach ($queues as $queue) {
            $this->redis->setex(self::WORKER_KEY_PREFIX . $queue, self::WORKER_TTL_SECONDS, '1');
        }
    }

    /** Whether any live worker has recently beaconed for this queue. */
    public function laneHasWorker(string $queue): bool
    {
        return (int) $this->redis->exists(self::WORKER_KEY_PREFIX . $queue) === 1;
    }

    /**
     * Disaster recovery after a Redis flush: push every pending job back
     * onto Redis. Duplicate list entries are harmless — claiming is atomic.
     */
    public function requeueFromDatabase(): int
    {
        $rows = $this->databases->engine()->fetchAllAssociative(
            "SELECT uuid FROM jobs WHERE status = 'pending'",
        );

        foreach ($rows as $row) {
            $this->pushExisting((string) $row['uuid']);
        }

        return \count($rows);
    }

    public function delay(string $uuid, \DateTimeImmutable $until): void
    {
        $this->redis->zadd(self::DELAYED_KEY, [$uuid => $until->getTimestamp()]);
    }

    /**
     * Cancels every PENDING job of a type for a site — including twins parked
     * in retry backoff — and removes them from Redis. Running jobs are left
     * to finish (their terminal transitions are status-guarded). The
     * user-facing "retry" paths call this BEFORE enqueueing a fresh job:
     * without it, the dedupe key hands back the parked twin, which may
     * already be at 4/5 attempts and dead-letters on its next failure — the
     * retry click then silently does nothing. Returns the cancelled count.
     */
    public function cancelPending(string $type, string $siteHandle): int
    {
        $db = $this->databases->engine();
        $uuids = $db->fetchFirstColumn(
            "SELECT uuid FROM jobs WHERE type = ? AND site_handle = ? AND status = 'pending'",
            [$type, $siteHandle],
        );

        $cancelled = 0;
        foreach ($uuids as $uuid) {
            $updated = $db->executeStatement(
                "UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE uuid = ? AND status = 'pending'",
                [$this->clock->iso(), (string) $uuid],
            );
            if ($updated === 1) {
                $this->forget((string) $uuid);
                ++$cancelled;
            }
        }

        return $cancelled;
    }

    /**
     * Cancels RUNNING jobs of a type for a site whose heartbeat is stale
     * (or missing) — zombie rows left behind when a worker was killed
     * mid-run (deploy SIGKILL, OOM, crash). The stale sweep retries such
     * rows with backoff, but a user-facing "retry" that just enqueued a
     * FRESH job wants them gone, not retried — otherwise the dashboard
     * keeps showing chunks "running" for hours. Guarded on the staleness
     * re-check, so a live worker that heartbeats between the SELECT and
     * the UPDATE keeps its job. Returns the cancelled count.
     */
    public function cancelStaleRunning(string $type, string $siteHandle, int $staleAfterSeconds = 900): int
    {
        $db = $this->databases->engine();
        $cutoff = $this->clock->now()
            ->sub(new \DateInterval('PT' . $staleAfterSeconds . 'S'))
            ->format('Y-m-d\TH:i:s\Z');

        $uuids = $db->fetchFirstColumn(
            "SELECT uuid FROM jobs
             WHERE type = ? AND site_handle = ? AND status = 'running'
               AND (heartbeat_at IS NULL OR heartbeat_at < ?)",
            [$type, $siteHandle, $cutoff],
        );

        $cancelled = 0;
        foreach ($uuids as $uuid) {
            $updated = $db->executeStatement(
                "UPDATE jobs SET status = 'cancelled', finished_at = ?,
                     error = 'Cancelled by retry: worker heartbeat stale — the process was interrupted mid-run.'
                 WHERE uuid = ? AND status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < ?)",
                [$this->clock->iso(), (string) $uuid, $cutoff],
            );
            if ($updated === 1) {
                $this->forget((string) $uuid);
                ++$cancelled;
            }
        }

        return $cancelled;
    }

    /**
     * Best-effort removal of a uuid from Redis (queue lists + delayed ZSET)
     * after a cancel. Claiming is status-guarded, so a leftover entry would
     * be harmless — this just keeps a cancelled job from being popped at all.
     */
    public function forget(string $uuid): void
    {
        foreach (self::QUEUES as $queue) {
            $this->redis->lrem(self::KEY_PREFIX . $queue, 0, $uuid);
        }
        $this->redis->zrem(self::DELAYED_KEY, $uuid);
    }
}
