<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Shared\Queue\LockBusyException;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

/**
 * Queue behavior against a real Redis (database 15, flushed per test —
 * never the app's database 0). Skipped when no Redis is reachable.
 */
final class QueueTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private JobQueue $queue;

    /** @var list<string> */
    public static array $executed = [];

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — queue tests skipped.');
        }
        $this->redis->flushdb();

        $this->dataPath = sys_get_temp_dir() . '/fastsite-queue-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $this->queue = new JobQueue($this->databases, $this->redis, $clock);
        self::$executed = [];
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                \assert($item instanceof \SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataPath);
        }
    }

    private function runner(JobHandler ...$handlers): JobRunner
    {
        return new JobRunner(
            $this->queue,
            new JobHandlerRegistry($handlers),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
        );
    }

    public function testDedupeCoalescesBursts(): void
    {
        $a = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'build:demo');
        $b = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'build:demo');

        self::assertSame($a, $b);
        self::assertSame(1, (int) $this->databases->engine()->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testDedupeDoesNotCoalesceIntoRunningTwin(): void
    {
        // A running build already snapshotted its input — a new trigger
        // must become a NEW pending job, not merge into the running one.
        $a = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'build:demo');
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'running', heartbeat_at = ? WHERE uuid = ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $a],
        );

        $b = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'build:demo');

        self::assertNotSame($a, $b);
        self::assertSame(2, (int) $this->databases->engine()->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testDuplicatePopCannotBypassBackoff(): void
    {
        // A failed job waiting out its backoff must not run early just
        // because a stray duplicate uuid sits on the queue list.
        $uuid = $this->queue->enqueue('test_ok', 'demo');
        $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $this->databases->engine()->executeStatement(
            'UPDATE jobs SET run_after = ? WHERE uuid = ?',
            [$future, $uuid],
        );
        $this->redis->lpush('fastsite:queue:high', [$uuid]); // stray duplicate

        $this->runner(new OkHandler())->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT status, attempts FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame([], QueueTest::$executed);
    }

    public function testChainExecutesInOrder(): void
    {
        $this->queue->enqueue('test_ok', 'demo', ['step' => 'first'], 'high', null, [
            ['type' => 'test_second', 'payload' => ['step' => 'second']],
        ]);

        $runner = $this->runner(new OkHandler(), new SecondHandler());
        $runner->work(maxRuntimeSeconds: 10, once: true);
        $runner->work(maxRuntimeSeconds: 10, once: true);

        self::assertSame(['test_ok:first', 'test_second:second'], self::$executed);
        $statuses = $this->databases->engine()->fetchFirstColumn('SELECT status FROM jobs ORDER BY id');
        self::assertSame(['done', 'done'], $statuses);
    }

    public function testFailureRetriesWithBackoffThenDeadLetters(): void
    {
        $uuid = $this->queue->enqueue('test_fail', 'demo');
        $this->databases->engine()->executeStatement('UPDATE jobs SET max_attempts = 2 WHERE uuid = ?', [$uuid]);

        $runner = $this->runner(new FailHandler());
        $runner->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT * FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['run_after']);
        self::assertStringContainsString('boom', (string) $row['error']);

        // Force the retry due now, second failure hits max_attempts → dead.
        $this->databases->engine()->executeStatement('UPDATE jobs SET run_after = NULL WHERE uuid = ?', [$uuid]);
        $this->redis->zrem('fastsite:queue:delayed', $uuid);
        $this->redis->lpush('fastsite:queue:high', [$uuid]);
        $runner->work(maxRuntimeSeconds: 10, once: true);

        $status = $this->databases->engine()->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertSame('dead', $status);
    }

    public function testStaleRunningJobIsReclaimed(): void
    {
        $uuid = $this->queue->enqueue('test_ok', 'demo');
        $stale = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'running', attempts = 1, heartbeat_at = ?, started_at = ? WHERE uuid = ?",
            [$stale, $stale, $uuid],
        );
        $this->redis->flushdb(); // worst case: Redis lost it too

        $reclaimed = $this->runner(new OkHandler())->sweepStaleJobs(force: true);

        self::assertSame(1, $reclaimed);
        $row = $this->databases->engine()->fetchAssociative('SELECT status, error FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('pending', $row['status']);
        self::assertStringContainsString('stale heartbeat', (string) $row['error']);
    }

    public function testLockBusyRequeuesWithoutBurningAnAttempt(): void
    {
        $uuid = $this->queue->enqueue('test_locked', 'demo');

        $this->runner(new LockedHandler())->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT status, attempts, run_after FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNotNull($row['run_after']);
    }
}

final class OkHandler implements JobHandler
{
    public static function type(): string
    {
        return 'test_ok';
    }

    public function run(Job $job, JobContext $context): void
    {
        $context->heartbeat();
        QueueTest::$executed[] = 'test_ok:' . ($job->payload['step'] ?? '');
    }
}

final class SecondHandler implements JobHandler
{
    public static function type(): string
    {
        return 'test_second';
    }

    public function run(Job $job, JobContext $context): void
    {
        QueueTest::$executed[] = 'test_second:' . ($job->payload['step'] ?? '');
    }
}

final class FailHandler implements JobHandler
{
    public static function type(): string
    {
        return 'test_fail';
    }

    public function run(Job $job, JobContext $context): void
    {
        throw new \RuntimeException('boom');
    }
}

final class LockedHandler implements JobHandler
{
    public static function type(): string
    {
        return 'test_locked';
    }

    public function run(Job $job, JobContext $context): void
    {
        throw new LockBusyException('site locked');
    }
}
