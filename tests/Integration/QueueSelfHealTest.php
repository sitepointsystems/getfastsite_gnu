<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Shared\Queue\QueueSelfHeal;
use Predis\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The web-layer fallback sweeper: when every background process is wedged
 * (the real incident), authenticated requests reclaim stale-running jobs
 * and promote delayed ones — mutex-gated so only one request per window
 * pays for it.
 */
final class QueueSelfHealTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private JobQueue $queue;
    private QueueSelfHeal $heal;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — queue tests skipped.');
        }
        $this->redis->flushdb();

        $this->dataPath = sys_get_temp_dir() . '/fastsite-heal-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $this->queue = new JobQueue($this->databases, $this->redis, $clock);
        $runner = new JobRunner($this->queue, new JobHandlerRegistry([]), $this->databases, $clock, new NullLogger());
        $this->heal = new QueueSelfHeal($runner, $this->queue, $this->redis, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (!isset($this->databases)) {
            return;
        }
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    private function zombie(): string
    {
        $uuid = $this->queue->enqueue('test_ok', 'demo', [], 'high');
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'running', attempts = 1, heartbeat_at = ? WHERE uuid = ?",
            [gmdate('Y-m-d\TH:i:s\Z', time() - 3600), $uuid],
        );

        return $uuid;
    }

    private function jobStatus(string $uuid): string
    {
        return (string) $this->databases->engine()->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$uuid]);
    }

    public function testHealReclaimsZombiesAndIsMutexGated(): void
    {
        // A stale-running zombie gets reclaimed by the first request...
        $first = $this->zombie();
        $this->heal->maybeHeal();
        self::assertSame('pending', $this->jobStatus($first), 'zombie reclaimed into a backoff retry');

        // ...but within the mutex window a second request does nothing.
        $second = $this->zombie();
        $this->heal->maybeHeal();
        self::assertSame('running', $this->jobStatus($second), 'mutex holds — no per-request sweeping');

        // Window over (mutex expired) → the next request heals again.
        $this->redis->del('fastsite:selfheal:web');
        $this->heal->maybeHeal();
        self::assertSame('pending', $this->jobStatus($second));
    }

    public function testHealSurvivesRedisOutage(): void
    {
        // A dead Redis must never break the user request the heal rides on.
        $broken = new QueueSelfHeal(
            new JobRunner($this->queue, new JobHandlerRegistry([]), $this->databases, new SystemClock(), new NullLogger()),
            $this->queue,
            new Client('tcp://127.0.0.1:1'), // nothing listens here
            new NullLogger(),
        );

        $broken->maybeHeal();
        self::assertTrue(true, 'no exception escaped');
    }
}
