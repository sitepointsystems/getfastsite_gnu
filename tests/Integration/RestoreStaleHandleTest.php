<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Fastsite\Backup\Service\BackupService;
use Fastsite\Backup\Storage\LocalStorageClient;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

/**
 * A `restore_site` replaces the site.db FILE. `SiteDatabaseManager` caches
 * one connection per site for the life of the process, and only the process
 * that ran the restore closes its own handle — so on Linux every other
 * long-lived process keeps reading, and WRITING, the displaced inode, with no
 * error and no lock contention (SQLite locks the file, and the two processes
 * now hold different files). Nothing recycles them either: no production
 * worker runs with --max-runtime. `restore_site` goes to the `high` lane,
 * which only the general worker serves, so the BUILD worker — the one that
 * next rebuilds the site — is guaranteed to be a different process, and its
 * next build silently republished the pre-restore content.
 *
 * TWO MANAGERS OVER ONE DATA DIRECTORY is the whole point: one stands in for
 * the worker, the other for the process running the restore.
 *
 * The first test FAILS BEFORE THE FIX ON BOTH PLATFORMS, for two different
 * reasons, which is worth stating because this project has no CI:
 *  - Linux (production): the swap succeeds and the second job reads the
 *    PRE-RESTORE row through the stale handle.
 *  - Windows (dev): the rename is REFUSED outright while a handle is open
 *    (error 32), so the restore throws swapDb's "open in another process".
 * Once the worker releases its site connections between jobs there is no open
 * handle at restore time, and both platforms agree.
 */
final class RestoreStaleHandleTest extends TestCase
{
    /** @var list<string> */
    public static array $reads = [];

    private string $dataPath;
    private SiteDatabaseManager $worker;   // stands in for a queue worker
    private SiteDatabaseManager $restorer; // stands in for the restoring process
    private Client $redis;
    private JobQueue $workerQueue;
    private ContentService $content;
    private BackupService $backups;
    private SiteService $sites;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — stale-handle tests skipped.');
        }
        $this->redis->flushdb();
        self::$reads = [];

        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-stale-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->worker = new SiteDatabaseManager($this->dataPath);
        $this->restorer = new SiteDatabaseManager($this->dataPath);

        $migrator = new Migrator($clock);
        $migrator->migrate($this->restorer->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService(
            new DbalSiteRepository($this->restorer, $clock),
            $this->restorer,
            $migrator,
            $clock,
            new NullLogger(),
            $appRoot,
        );
        $this->content = new ContentService(
            new DbalContentRepository($this->restorer, $clock),
            new ContentSanitizer(),
            $clock,
            new DbalMediaRepository($this->restorer, $clock),
        );
        // The worker pops through its OWN manager — that cache is under test.
        $this->workerQueue = new JobQueue($this->worker, $this->redis, $clock);
        $this->backups = new BackupService(
            $this->restorer,
            new LocalStorageClient($this->dataPath . '/backups-store'),
            new JobQueue($this->restorer, $this->redis, $clock),
            $clock,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->worker->closeAll();
        $this->restorer->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dataPath);
    }

    public function testAWorkerSeesARestoreRunByAnotherProcess(): void
    {
        $site = $this->seed();
        $this->backups->backupSiteDb($site, 'test');

        // Move the live DB on from the snapshot, so "restored" is observable.
        $this->content->update($site, $this->rowId(), ['title' => 'After backup']);

        // Job 1: the worker opens and caches its site connection.
        $this->runProbe();
        self::assertSame(['After backup'], self::$reads, 'The worker must start from the live row.');

        // Another process restores. Nothing tells the worker.
        $this->backups->restoreSiteDb($site);

        // Job 2: the SAME worker, the SAME manager instance.
        $this->runProbe();

        self::assertSame(
            ['After backup', 'Before backup'],
            self::$reads,
            'The second job must read the RESTORED row — a cached handle serves the displaced file.',
        );
    }

    /**
     * The mechanism, asserted directly rather than through its symptom: the
     * loop must not leave a site connection cached once a job is done. This is
     * the guard that still runs on a platform where the rename above cannot
     * even be attempted, and the one that fails if the release is ever
     * deleted.
     */
    public function testTheWorkerDropsSiteConnectionsAfterEveryJob(): void
    {
        $this->seed();

        $this->runProbe();

        self::assertSame(['Before backup'], self::$reads, 'The probe must really have opened the site DB.');
        self::assertSame([], $this->cachedSites(), 'A finished job must leave no site connection cached.');
    }

    /**
     * The displaced DB is kept as `.pre-restore-{stamp}` precisely so a bad
     * restore is undoable — and it was a husk. The rename moves site.db and
     * NOT site.db-wal (a sidecar keeps the name the connection opened), so
     * swapDb's sidecar unlink — correct and necessary for the DB coming IN —
     * destroyed the displaced DB's un-checkpointed tail, which under WAL +
     * synchronous=NORMAL is every recent write. Verified in a Linux container
     * before the fix: a fresh reader of the preserved file got a disk I/O
     * error while the old handle lived, and `no such table` once it closed.
     */
    public function testThePreservedPreRestoreDbIsSelfContained(): void
    {
        $site = $this->seed();
        $this->backups->backupSiteDb($site, 'test');
        $this->content->update($site, $this->rowId(), ['title' => 'After backup']);

        $result = $this->backups->restoreSiteDb($site);

        $preserved = (string) $result['preserved_as'];
        self::assertNotSame('', $preserved, 'A restore must keep the DB it displaced.');
        self::assertFileExists($preserved);

        // Read it the way someone recovering from a bad restore would: a fresh
        // connection, with no WAL sidecar anywhere near it.
        $probe = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $preserved]);
        try {
            self::assertSame(
                'After backup',
                (string) $probe->fetchOne('SELECT title FROM content WHERE slug = ?', ['p']),
                'The preserved DB must still hold what was live when it was displaced.',
            );
        } finally {
            $probe->close();
        }
    }

    private function seed(): Site
    {
        $site = $this->sites->create('stalesite', 'Stale Site', 'https://stale.example.com');
        $this->content->create($site, [
            'type' => 'page',
            'title' => 'Before backup',
            'slug' => 'p',
            'status' => 'published',
        ]);

        return $site;
    }

    private function rowId(): int
    {
        return (int) $this->restorer->site('stalesite')->fetchOne('SELECT id FROM content WHERE slug = ?', ['p']);
    }

    /** Runs exactly one probe job through a runner on the worker's manager. */
    private function runProbe(): void
    {
        // `default` only: restoreSiteDb enqueues its own build_full, which
        // TYPE_QUEUES routes to the `build` lane — popping that instead of the
        // probe would leave this test asserting nothing.
        $this->workerQueue->enqueue('test_probe_read', 'stalesite', [], 'default');

        $runner = new JobRunner(
            $this->workerQueue,
            new JobHandlerRegistry([new ProbeReadHandler($this->worker)]),
            $this->worker,
            new SystemClock(),
            new NullLogger(),
        );

        self::assertSame(1, $runner->work(null, true, ['default']));
    }

    /** @return list<string> */
    private function cachedSites(): array
    {
        $prop = new \ReflectionProperty(SiteDatabaseManager::class, 'sites');

        /** @var array<string, mixed> $sites */
        $sites = $prop->getValue($this->worker);

        return array_keys($sites);
    }
}

/**
 * Reads one title through the manager it was handed — that is, through the
 * worker's own connection cache, which is the thing under test.
 */
final class ProbeReadHandler implements JobHandler
{
    public function __construct(private readonly SiteDatabaseManager $databases)
    {
    }

    public static function type(): string
    {
        return 'test_probe_read';
    }

    public function run(Job $job, JobContext $context): void
    {
        $context->heartbeat();

        RestoreStaleHandleTest::$reads[] = (string) $this->databases->site((string) $job->siteHandle)
            ->fetchOne('SELECT title FROM content WHERE slug = ?', ['p']);
    }
}
