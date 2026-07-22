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
    public const QUEUES = ['high', 'default', 'low'];

    private const KEY_PREFIX = 'fastsite:queue:';
    private const DELAYED_KEY = 'fastsite:queue:delayed';

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
     * Blocking pop across queues, high first. Returns a job uuid or null.
     */
    public function pop(int $timeoutSeconds = 5): ?string
    {
        $keys = array_map(static fn (string $q): string => self::KEY_PREFIX . $q, self::QUEUES);
        $result = $this->redis->brpop($keys, $timeoutSeconds);

        return $result !== null ? (string) $result[1] : null;
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
}
