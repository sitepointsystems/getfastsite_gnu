<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Shared\Queue\Scheduler;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\Repository\DbalTranslationRepository;
use Fastsite\Translation\Service\TranslationService;
use Predis\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The scheduler's content reconciler must not manufacture jobs: while a
 * translate/build for the site is in flight (pending, or running with a
 * LIVE heartbeat) it stays quiet — the dedupe key only coalesces PENDING
 * twins, so without the in-flight check every 60s tick spawned another job
 * while one was running, and a crash-looping worker turned that into an
 * unbounded pile of zombie "running" rows. A zombie (running, stale
 * heartbeat) must NOT block it, though — reconciliation has to survive the
 * exact failure the zombies came from.
 */
final class SchedulerReconcileTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private JobQueue $queue;
    private Scheduler $scheduler;
    private ContentService $content;
    private \Fastsite\Site\Dto\Site $site;
    private DbalContentRepository $contentRepo;
    private SystemClock $clock;

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
        $this->dataPath = sys_get_temp_dir() . '/fastsite-sched-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $site = $sites->create('schedtest', 'Sched Test', 'https://sched.example.com');
        $sites->addLocale('schedtest', 'da');

        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $this->queue = new JobQueue($this->databases, $this->redis, $clock);
        $translations = new TranslationService(
            $contentRepo,
            new DbalTranslationRepository($this->databases, $clock),
            new TruncateOnlyAiFactory(),
            new ContentSanitizer(),
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $clock),
        );
        $publish = new PublishTrigger($this->queue, $translations);
        // Built WITH the trigger, like the real container: a purge deletes the
        // row whose timestamp would otherwise signal the change, so it is the
        // one content write that has to queue its own build.
        $this->content = new ContentService(
            $contentRepo,
            new ContentSanitizer(),
            $clock,
            new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock),
            $publish,
        );
        $this->content->create($site, ['type' => 'post', 'title' => 'Unbuilt post', 'body_html' => '<p>x</p>', 'status' => 'published']);

        $this->scheduler = new Scheduler(
            $siteRepo,
            $this->databases,
            $this->queue,
            new JobRunner($this->queue, new JobHandlerRegistry([]), $this->databases, $clock, new NullLogger()),
            $publish,
            $translations,
            $clock,
            new NullLogger(),
        );
        $this->site = $site;
        $this->contentRepo = $contentRepo;
        $this->clock = $clock;
    }

    /** A done build that started at `$startedAt`, replacing any earlier one. */
    private function recordBuild(string $startedAt): void
    {
        $this->databases->engine()->executeStatement("DELETE FROM builds WHERE site_handle = 'schedtest'");
        $this->databases->engine()->executeStatement(
            "INSERT INTO builds (site_handle, status, started_at, finished_at) VALUES ('schedtest', 'done', ?, ?)",
            [$startedAt, $startedAt],
        );
    }

    private static function shift(string $stamp, int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($stamp) + $seconds);
    }

    /**
     * Single-locale for the deletion tests: a multilingual site has a
     * translation gap of its own (covered above) and it would answer for the
     * reconciler before the deletion ever got a chance to.
     */
    private function dropLocales(): void
    {
        $this->databases->engine()->executeStatement("UPDATE sites SET locales = '[]' WHERE handle = 'schedtest'");
    }

    private function onlyContentId(): int
    {
        return (int) $this->databases->site('schedtest')->fetchOne('SELECT id FROM content ORDER BY id LIMIT 1');
    }

    protected function tearDown(): void
    {
        if (!isset($this->databases)) {
            return; // setUp skipped before wiring (no Redis)
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

    private function reconcile(): void
    {
        $m = new \ReflectionMethod($this->scheduler, 'reconcileUnbuiltContent');
        $m->invoke($this->scheduler);
    }

    private function jobCount(): int
    {
        return (int) $this->databases->engine()->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE site_handle = 'schedtest'",
        );
    }

    public function testReconcilerNeverStacksJobsWhileOneIsInFlight(): void
    {
        // Unbuilt content, empty queue → one job.
        $this->reconcile();
        self::assertSame(1, $this->jobCount());
        $uuid = (string) $this->databases->engine()->fetchOne('SELECT uuid FROM jobs LIMIT 1');

        // Still pending → next tick stays quiet (dedupe would catch this
        // too, but the in-flight check must not depend on it).
        $this->reconcile();
        self::assertSame(1, $this->jobCount());

        // Running with a LIVE heartbeat → still quiet. Before the in-flight
        // check, THIS was the zombie factory: dedupe only sees pending, so
        // every tick enqueued another job alongside the running one.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'running', heartbeat_at = ? WHERE uuid = ?",
            [$now, $uuid],
        );
        $this->reconcile();
        self::assertSame(1, $this->jobCount());

        // Heartbeat goes stale (worker killed mid-run) → the zombie must
        // NOT block reconciliation: a fresh job appears next tick.
        $stale = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET heartbeat_at = ? WHERE uuid = ?",
            [$stale, $uuid],
        );
        $this->reconcile();
        self::assertSame(2, $this->jobCount());
    }

    /**
     * THE BUG THIS EXISTS FOR: deleting a page never took it off the live
     * site. Nothing on the delete path publishes — content writes are left to
     * this reconciler, which coalesces a burst of them into one build — and
     * the reconciler's probe filtered out trashed rows. A deletion is the only
     * content change that LOWERS the maximum of what is left, so the level
     * never rose, no build was ever queued, and the page stayed on the CDN
     * with the row sitting in the trash on every screen. Found live: 7+
     * minutes of HTTP 200 on a page whose content row no longer existed.
     */
    public function testDeletingPublishedContentQueuesTheBuildThatRemovesItsPage(): void
    {
        $this->dropLocales();
        $id = $this->onlyContentId();
        $this->content->softDelete($this->site, $id);

        // softDelete bumps updated_at — that is the signal, and it was being
        // discarded rather than missing.
        $deletedAt = (string) $this->databases->site('schedtest')
            ->fetchOne('SELECT updated_at FROM content WHERE id = ?', [$id]);

        // A build that already ran after the deletion: the output covers it.
        $this->recordBuild(self::shift($deletedAt, 60));
        $this->reconcile();
        self::assertSame(0, $this->jobCount(), 'output already covers the deletion — no spurious build');

        // And the real case: the deletion is newer than the last build.
        $this->recordBuild(self::shift($deletedAt, -60));
        $this->reconcile();
        self::assertSame(1, $this->jobCount(), 'a deletion must queue the build that takes the page off the CDN');
    }

    public function testDeletingADraftQueuesNothing(): void
    {
        $this->dropLocales();
        $draft = $this->content->create($this->site, ['type' => 'post', 'title' => 'Draft', 'body_html' => '<p>d</p>']);
        $this->content->softDelete($this->site, $draft->id);

        // The published post is still live and still built; only the draft
        // moved. A draft has no page, so its deletion must not cost a build —
        // which is what the status filter in the probe is still there for.
        $newest = (string) $this->databases->site('schedtest')
            ->fetchOne("SELECT MAX(updated_at) FROM content WHERE status = 'published'");
        $this->recordBuild(self::shift($newest, 60));
        $this->reconcile();
        self::assertSame(0, $this->jobCount(), 'deleting a draft changes no output');
    }

    /**
     * A PURGE is the one content write no level-based reconciler can ever
     * detect: it deletes the row whose timestamp would signal it, so the
     * maximum goes down and there is nothing left to react to. It therefore
     * has to queue its own build — and this proves both halves, because the
     * same purge through a ContentService without the trigger leaves the
     * reconciler with nothing to find.
     */
    public function testPurgingPublishedContentQueuesItsOwnBuild(): void
    {
        $this->dropLocales();
        $second = $this->content->create($this->site, ['type' => 'post', 'title' => 'Second', 'body_html' => '<p>2</p>', 'status' => 'published']);
        $third = $this->content->create($this->site, ['type' => 'post', 'title' => 'Third', 'body_html' => '<p>3</p>', 'status' => 'published']);

        // A done build newer than every row: nothing is stale, so the
        // reconciler is the quiet baseline this measures against.
        $this->recordBuild(gmdate('Y-m-d\TH:i:s\Z', time() + 60));
        $this->reconcile();
        self::assertSame(0, $this->jobCount());

        $this->content->purge($this->site, $second->id);
        self::assertSame(1, $this->jobCount(), 'a purge must queue its own build — nothing else ever will');

        // The unwired path, which is what the container test guards against.
        $bare = new ContentService(
            $this->contentRepo,
            new ContentSanitizer(),
            $this->clock,
            new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $this->clock),
        );
        $this->databases->engine()->executeStatement("DELETE FROM jobs WHERE site_handle = 'schedtest'");
        $bare->purge($this->site, $third->id);
        $this->reconcile();
        self::assertSame(
            0,
            $this->jobCount(),
            'without the trigger a purge is invisible to the reconciler — the page would stay live',
        );
    }

    public function testTranslationGapRevivesWithEscalatingCooldown(): void
    {
        // The built output covers the content (a done build newer than every
        // row), but translations are missing and NOTHING is driving them —
        // the exact state a dead translate job leaves behind. The reconciler
        // must revive the pipeline.
        $now = gmdate('Y-m-d\TH:i:s\Z', time() + 60);
        $this->databases->engine()->executeStatement(
            "INSERT INTO builds (site_handle, status, started_at, finished_at) VALUES ('schedtest', 'done', ?, ?)",
            [$now, $now],
        );

        $this->reconcile();
        self::assertSame(1, $this->jobCount(), 'pending translation work alone triggers a revival');
        $uuid = (string) $this->databases->engine()->fetchOne("SELECT uuid FROM jobs WHERE site_handle = 'schedtest'");

        // That job dead-letters moments ago → the escalating cooldown holds
        // the reconciler back (no tick-speed hammering of a broken gateway).
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET status = 'dead', finished_at = ? WHERE uuid = ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $uuid],
        );
        $this->reconcile();
        self::assertSame(1, $this->jobCount(), 'a fresh dead job parks the revival');

        // Once the cooldown has passed (1 dead job → 30 min), it revives.
        $this->databases->engine()->executeStatement(
            "UPDATE jobs SET finished_at = ? WHERE uuid = ?",
            [gmdate('Y-m-d\TH:i:s\Z', time() - 7200), $uuid],
        );
        $this->reconcile();
        self::assertSame(2, $this->jobCount(), 'cooldown elapsed → automatic revival');
    }
}
