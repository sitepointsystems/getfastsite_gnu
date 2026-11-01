<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Media\Job\OptimizeMediaHandler;
use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

/**
 * optimize_media chunking (media lane): a large batch is processed
 * IMAGES_PER_RUN at a time, the remainder re-enqueued at the back of the
 * media queue with the cumulative changed count carried in the payload, and
 * the (single) republish fires only from the completing chunk. Ids that fail
 * to optimize (here: nonexistent images) are skipped, never fatal. Real
 * Redis (db 15) for the queue + site lock; skipped when absent.
 */
final class OptimizeMediaChunkTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private JobQueue $queue;
    private JobRunner $runner;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — queue tests skipped.');
        }
        $this->redis->flushdb();

        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-optchunk-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('optchunk', 'Optimize Chunk Test', 'https://o.example.com');

        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $media = new MediaService($mediaRepo, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);

        $this->queue = new JobQueue($this->databases, $this->redis, $clock);
        $translations = new \Fastsite\Translation\Service\TranslationService(
            new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            new \Fastsite\Tests\Support\UnusedAiClientFactory(),
            new \Fastsite\Content\Service\ContentSanitizer(),
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $clock),
        );
        $handler = new OptimizeMediaHandler(
            $media,
            $siteRepo,
            new SiteLock($this->redis),
            new PublishTrigger($this->queue, $translations),
            $this->queue,
            new NullLogger(),
        );
        $this->runner = new JobRunner($this->queue, new JobHandlerRegistry([$handler]), $this->databases, $clock, new NullLogger());
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

    public function testChunksResumeViaPayloadAndPublishOnlyOnCompletion(): void
    {
        // 30 ids that don't exist — every optimize attempt fails-and-skips,
        // which exercises slicing + payload carry without real image files.
        $this->queue->enqueue('optimize_media', 'optchunk', ['ids' => range(1, 30)], 'default', 'optimize:optchunk');

        // Routed to the media lane regardless of the caller's queue.
        self::assertSame(1, (int) $this->redis->llen('fastsite:queue:media'));

        // Chunk 1: 25 processed, 5 re-enqueued at the back of the media lane.
        $this->runner->work(maxRuntimeSeconds: 10, once: true);

        $next = $this->databases->engine()->fetchAssociative(
            "SELECT queue, dedupe_key, payload FROM jobs WHERE type = 'optimize_media' AND status = 'pending'",
        );
        self::assertNotFalse($next, 'the incomplete chunk must re-enqueue itself');
        self::assertSame('media', $next['queue']);
        self::assertSame('optimize:optchunk', $next['dedupe_key']);
        $payload = json_decode((string) $next['payload'], true);
        self::assertSame(range(26, 30), $payload['ids']);
        self::assertSame(0, $payload['changed']);
        self::assertSame(
            0,
            (int) $this->databases->engine()->fetchOne("SELECT COUNT(*) FROM jobs WHERE type IN ('build_full', 'translate_content')"),
            'no publish before the batch completes',
        );

        // Chunk 2 completes; nothing changed → no publish chain at all.
        $this->runner->work(maxRuntimeSeconds: 10, once: true);

        self::assertSame(
            2,
            (int) $this->databases->engine()->fetchOne("SELECT COUNT(*) FROM jobs WHERE type = 'optimize_media' AND status = 'done'"),
        );
        self::assertSame(
            0,
            (int) $this->databases->engine()->fetchOne("SELECT COUNT(*) FROM jobs WHERE type IN ('build_full', 'translate_content')"),
        );
    }
}
