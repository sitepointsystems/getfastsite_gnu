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

    public function testPromoteNowKicksBackoffParkedTwin(): void
    {
        // The dashboard "retry" path: a failed job parked in backoff absorbs
        // the retry enqueue via dedupe — promoteNow must clear the backoff
        // and run it immediately, or the click waits out up to an hour.
        $uuid = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'translate:demo');
        $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $this->databases->engine()->executeStatement(
            'UPDATE jobs SET run_after = ? WHERE uuid = ?',
            [$future, $uuid],
        );
        $this->redis->del('fastsite:queue:high');
        $this->redis->zadd('fastsite:queue:delayed', [$uuid => time() + 3600]);

        $twin = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'translate:demo');
        self::assertSame($uuid, $twin); // absorbed — still parked

        $this->queue->promoteNow($twin);
        $this->runner(new OkHandler())->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT status, run_after FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('done', $row['status']);
    }

    public function testPromoteNowIgnoresNonPendingJobs(): void
    {
        $uuid = $this->queue->enqueue('test_ok', 'demo');
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'dead' WHERE uuid = ?",
            [$uuid],
        );
        $this->redis->del('fastsite:queue:high');

        $this->queue->promoteNow($uuid);

        self::assertSame(0, (int) $this->redis->llen('fastsite:queue:high'));
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

    public function testCancelledJobIsNeverClaimed(): void
    {
        // Cancel happens between enqueue and pop: the uuid still sits on the
        // Redis list, but the status-guarded claim must skip it untouched.
        $uuid = $this->queue->enqueue('test_ok', 'demo');
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE uuid = ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $uuid],
        );

        $this->runner(new OkHandler())->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT status, attempts FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('cancelled', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame([], QueueTest::$executed);
    }

    public function testCancelPendingClearsQueuedAndParkedJobsForSite(): void
    {
        // The user-facing retry paths clear EVERY pending job of the type
        // for the site — queued or backoff-parked — before enqueueing a
        // fresh one, so the retry never inherits a twin's spent attempts.
        $queued = $this->queue->enqueue('test_ok', 'demo', [], 'high');
        $parked = $this->queue->enqueue('test_ok', 'demo', [], 'high', 'park:demo');
        $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $this->databases->engine()->executeStatement(
            'UPDATE jobs SET run_after = ? WHERE uuid = ?',
            [$future, $parked],
        );
        $this->redis->zadd('fastsite:queue:delayed', [$parked => time() + 3600]);
        $other = $this->queue->enqueue('test_ok', 'othersite', [], 'high');

        self::assertSame(2, $this->queue->cancelPending('test_ok', 'demo'));

        $status = fn (string $uuid): string => (string) $this->databases->engine()
            ->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertSame('cancelled', $status($queued));
        self::assertSame('cancelled', $status($parked));
        self::assertSame('pending', $status($other), 'another site\'s job must be untouched');

        // Redis is clean too: only the other site's uuid remains on the list,
        // and the parked twin left the delayed ZSET.
        self::assertSame([$other], $this->redis->lrange('fastsite:queue:high', 0, -1));
        self::assertNull($this->redis->zscore('fastsite:queue:delayed', $parked));
    }

    public function testCancelStaleRunningClearsZombiesButKeepsLiveJobs(): void
    {
        // Three running jobs: one with a stale heartbeat (worker killed
        // mid-run), one with NO heartbeat, one alive. Retry must clear the
        // zombies and leave the live one working.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $mk = function (string $site, ?string $heartbeat): string {
            $uuid = $this->queue->enqueue('test_ok', $site, [], 'high');
            $this->databases->engine()->executeStatement(
                "UPDATE jobs SET status = 'running', heartbeat_at = ? WHERE uuid = ?",
                [$heartbeat, $uuid],
            );

            return $uuid;
        };
        $stale = $mk('demo', $old);
        $orphan = $mk('demo', null);
        $alive = $mk('demo', $now);

        self::assertSame(2, $this->queue->cancelStaleRunning('test_ok', 'demo'));

        $status = fn (string $uuid): string => (string) $this->databases->engine()
            ->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertSame('cancelled', $status($stale));
        self::assertSame('cancelled', $status($orphan));
        self::assertSame('running', $status($alive), 'a live worker keeps its job');
    }

    public function testForgetRemovesFromRedis(): void
    {
        $uuid = $this->queue->enqueue('test_ok', 'demo');
        self::assertSame(1, (int) $this->redis->llen('fastsite:queue:default'));
        $this->redis->zadd('fastsite:queue:delayed', [$uuid => time() + 3600]);

        $this->queue->forget($uuid);

        self::assertSame(0, (int) $this->redis->llen('fastsite:queue:default'));
        self::assertNull($this->redis->zscore('fastsite:queue:delayed', $uuid));
    }

    public function testCancelDuringRunDropsCompletionAndChain(): void
    {
        // The operator cancels while the handler runs: the in-flight step
        // finishes, but the status-guarded completion loses — the job stays
        // cancelled and the chain step is never enqueued.
        $this->queue->enqueue('test_cancel_mid', 'demo', [], 'default', null, [
            ['type' => 'test_second'],
        ]);

        $this->runner(new CancelMidRunHandler($this->databases), new SecondHandler())
            ->work(maxRuntimeSeconds: 10, once: true);

        $statuses = $this->databases->engine()->fetchFirstColumn('SELECT status FROM jobs ORDER BY id');
        self::assertSame(['cancelled'], $statuses);
    }

    public function testProgressNoteSurvivesFailureAndClearsOnCompletion(): void
    {
        ProgressThenFailHandler::$failed = false;
        $uuid = $this->queue->enqueue('test_progress', 'demo');
        $runner = $this->runner(new ProgressThenFailHandler());

        $runner->work(maxRuntimeSeconds: 10, once: true);

        // The failed attempt keeps its last note — shows where it died.
        $row = $this->databases->engine()->fetchAssociative('SELECT status, progress FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('halfway…', $row['progress']);

        // Retry succeeds → done, note cleared.
        $this->databases->engine()->executeStatement('UPDATE jobs SET run_after = NULL WHERE uuid = ?', [$uuid]);
        $this->redis->zrem('fastsite:queue:delayed', $uuid);
        $this->redis->lpush('fastsite:queue:default', [$uuid]);
        $runner->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative('SELECT status, progress FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertNotFalse($row);
        self::assertSame('done', $row['status']);
        self::assertNull($row['progress']);
    }

    public function testTypeRoutingOverridesCallerQueue(): void
    {
        // Lane types are routed centrally — whatever queue a call site
        // passes, translate_content must land on the translate lane.
        $uuid = $this->queue->enqueue('translate_content', 'demo', [], 'default', 'translate:demo');

        $queue = $this->databases->engine()->fetchOne('SELECT queue FROM jobs WHERE uuid = ?', [$uuid]);
        self::assertSame('translate', $queue);
        self::assertSame([$uuid], $this->redis->lrange('fastsite:queue:translate', 0, -1));
        self::assertSame(0, (int) $this->redis->llen('fastsite:queue:default'));
    }

    public function testChainStepRoutesThroughMap(): void
    {
        // A chain step inherits the parent's queue UNLESS the type map says
        // otherwise — publish_git must always land on the build lane.
        $this->queue->enqueue('test_ok', 'demo', [], 'high', null, [
            ['type' => 'publish_git'],
        ]);

        $this->runner(new OkHandler())->work(maxRuntimeSeconds: 10, once: true);

        $row = $this->databases->engine()->fetchAssociative(
            "SELECT queue, status FROM jobs WHERE type = 'publish_git'",
        );
        self::assertNotFalse($row);
        self::assertSame('build', $row['queue']);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $this->redis->llen('fastsite:queue:build'));
    }

    public function testWorkerServesOnlyItsQueues(): void
    {
        $low = $this->queue->enqueue('test_ok', 'demo', [], 'low');
        $high = $this->queue->enqueue('test_second', 'demo', [], 'high');

        $this->runner(new OkHandler(), new SecondHandler())->work(maxRuntimeSeconds: 10, once: true, queues: ['low']);

        self::assertSame('done', $this->databases->engine()->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$low]));
        self::assertSame('pending', $this->databases->engine()->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$high]));
        self::assertSame(1, (int) $this->redis->exists('fastsite:worker:low'));
        self::assertSame(0, (int) $this->redis->exists('fastsite:worker:high'));
    }

    public function testPopRejectsUnknownQueue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queue->pop(1, ['bogus']);
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

final class CancelMidRunHandler implements JobHandler
{
    public function __construct(private readonly SiteDatabaseManager $databases)
    {
    }

    public static function type(): string
    {
        return 'test_cancel_mid';
    }

    public function run(Job $job, JobContext $context): void
    {
        // Simulates the operator clicking Cancel while this job runs.
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE uuid = ? AND status = 'running'",
            [gmdate('Y-m-d\TH:i:s\Z'), $job->uuid],
        );
    }
}

final class ProgressThenFailHandler implements JobHandler
{
    public static bool $failed = false;

    public static function type(): string
    {
        return 'test_progress';
    }

    public function run(Job $job, JobContext $context): void
    {
        $context->progress('halfway…');
        if (!self::$failed) {
            self::$failed = true;
            throw new \RuntimeException('first attempt boom');
        }
    }
}
