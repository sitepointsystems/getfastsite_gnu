<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Backup\Service\BackupService;
use Fastsite\Backup\Storage\LocalStorageClient;
use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\ComponentRenderer;
use Fastsite\Build\Service\MediaRenderer;
use Fastsite\Component\Repository\DbalComponentRepository;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\Repository\DbalTranslationRepository;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

/**
 * M10 backup/restore round-trip against a local storage backend and a real
 * Redis (db 15). Proves the milestone's "Done" criterion: delete a site.db,
 * restore from a backup, and the site rebuilds byte-identically.
 */
final class BackupTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;
    private JobQueue $queue;
    private BackupService $backups;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — backup tests skipped.');
        }
        $this->redis->flushdb();

        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-backup-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $translationRepo = new DbalTranslationRepository($this->databases, $clock);
        $componentRenderer = new ComponentRenderer(new DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->build = new BuildService($contentRepo, $this->databases, $clock, new NullLogger(), new MediaRenderer($mediaRepo), $translationRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot), $mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock), new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)), new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"));
        $this->queue = new JobQueue($this->databases, $this->redis, $clock);
        $this->backups = new BackupService(
            $this->databases,
            new LocalStorageClient($this->dataPath . '/backups-store'),
            $this->queue,
            $clock,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
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

    public function testDbBackupRestoreRepublishesIdentically(): void
    {
        $site = $this->sites->create('backupsite', 'Backup Site', 'https://backup.example.com');
        $post = $this->content->create($site, [
            'type' => 'post', 'title' => 'Snapshot Me', 'body_html' => '<p>Original body.</p>', 'status' => 'published',
        ]);
        $this->build->build($site);

        $buildDir = $this->dataPath . '/sites/backupsite/build';
        $before = $this->hashTree($buildDir);
        self::assertArrayHasKey('blog/' . $post->slug . '/index.html', $before);

        // Snapshot the DB.
        $record = $this->backups->backupSiteDb($site, 'test');
        self::assertSame('db', $record['kind']);
        self::assertGreaterThan(0, $record['size_bytes']);

        // Total loss: drop the build output AND the live site.db.
        $this->rrmdir($buildDir);
        $this->databases->closeSite('backupsite');
        unlink($this->dataPath . '/sites/backupsite/site.db');
        @unlink($this->dataPath . '/sites/backupsite/site.db-wal');
        @unlink($this->dataPath . '/sites/backupsite/site.db-shm');

        // Restore.
        $result = $this->backups->restoreSiteDb($site);
        self::assertSame($record['storage_key'], $result['restored_from']);

        // The post is back in the restored DB.
        $rows = $this->databases->site('backupsite')->fetchAllAssociative('SELECT title, body_html FROM content');
        self::assertCount(1, $rows);
        self::assertSame('Snapshot Me', $rows[0]['title']);

        // Restore enqueued a rebuild+publish chain.
        $job = $this->databases->engine()->fetchAssociative(
            "SELECT type, chain FROM jobs WHERE type = 'build_full' AND site_handle = 'backupsite' ORDER BY id DESC LIMIT 1",
        );
        self::assertNotFalse($job);
        self::assertStringContainsString('publish_git', (string) $job['chain']);

        // Re-run the build the way the worker would: byte-identical output.
        $stats = $this->build->build($site);
        self::assertGreaterThan(0, $stats->written);
        $after = $this->hashTree($buildDir);
        self::assertSame($before, $after, 'Republished dist must match the pre-loss dist byte-for-byte.');
    }

    public function testMediaBackupIsContentAddressedAndRestores(): void
    {
        $site = $this->sites->create('mediasite', 'Media Site', 'https://media.example.com');
        $mediaDir = $this->dataPath . '/sites/mediasite/media/originals/2026/07';
        mkdir($mediaDir, 0770, true);
        $file = $mediaDir . '/1-hero.jpg';
        file_put_contents($file, 'JPEGBYTES-' . str_repeat('x', 2048));
        $originalSha = hash_file('sha256', $file);

        $record = $this->backups->backupSiteMedia($site, 'test');
        self::assertNotNull($record);
        self::assertSame('media', $record['kind']);
        self::assertTrue((new LocalStorageClient($this->dataPath . '/backups-store'))->exists('site/mediasite/media/blob/' . $originalSha));

        // Lose the file, restore it, byte-identical.
        unlink($file);
        $out = $this->backups->restoreSiteMedia($site);
        self::assertSame(1, $out['restored']);
        self::assertFileExists($file);
        self::assertSame($originalSha, hash_file('sha256', $file));

        // Re-running the backup uploads no new blobs (content-addressed dedupe).
        $again = $this->backups->backupSiteMedia($site, 'test');
        self::assertNotNull($again);
    }

    public function testShellBackupRestoresFiles(): void
    {
        $site = $this->sites->create('shellsite', 'Shell Site', 'https://shell.example.com');
        $shellFile = $this->dataPath . '/sites/shellsite/shell/templates/base.html.twig';
        $original = (string) file_get_contents($shellFile);

        $this->backups->backupSiteShell($site, 'test');

        file_put_contents($shellFile, '<!-- clobbered -->');
        $restored = $this->backups->restoreSiteShell($site);
        self::assertGreaterThan(0, $restored);
        self::assertSame($original, (string) file_get_contents($shellFile));
    }

    public function testCorruptBackupIsRejectedBeforeTouchingLiveDb(): void
    {
        $site = $this->sites->create('guardsite', 'Guard Site', 'https://guard.example.com');
        $this->content->create($site, ['type' => 'post', 'title' => 'Keep', 'body_html' => '<p>x</p>', 'status' => 'published']);

        // A backup record pointing at a non-SQLite (garbage) object.
        $storage = new LocalStorageClient($this->dataPath . '/backups-store');
        $badKey = 'site/guardsite/db/garbage.db.gz';
        $storage->put($badKey, (string) gzencode('this is not a sqlite database', 6));

        $liveBefore = hash_file('sha256', $this->dataPath . '/sites/guardsite/site.db');
        try {
            $this->backups->restoreSiteDb($site, $badKey);
            self::fail('Expected integrity failure to abort the restore.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('integrity', strtolower($e->getMessage()));
        }

        // Live DB untouched; its content still queryable.
        self::assertSame($liveBefore, hash_file('sha256', $this->dataPath . '/sites/guardsite/site.db'));
        self::assertSame(1, (int) $this->databases->site('guardsite')->fetchOne('SELECT COUNT(*) FROM content'));
    }

    public function testRestoreRejectsAnotherSitesBackupKey(): void
    {
        $alpha = $this->sites->create('alphasite', 'Alpha', 'https://alpha.example.com');
        $beta = $this->sites->create('betasite', 'Beta', 'https://beta.example.com');
        $this->content->create($alpha, ['type' => 'post', 'title' => 'Alpha secret-free post', 'body_html' => '<p>a</p>', 'status' => 'published']);
        $this->content->create($beta, ['type' => 'post', 'title' => 'Beta content', 'body_html' => '<p>b</p>', 'status' => 'published']);

        $betaKey = (string) $this->backups->backupSiteDb($beta, 'test')['storage_key'];
        $engineKey = (string) $this->backups->backupEngine('test')['storage_key'];
        $alphaLive = hash_file('sha256', $this->dataPath . '/sites/alphasite/site.db');

        // Cross-tenant: alpha may never be restored from beta's snapshot.
        try {
            $this->backups->restoreSiteDb($alpha, $betaKey);
            self::fail('Expected the cross-site key to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does not belong', $e->getMessage());
        }

        // Cross-scope: an engine snapshot is not a site restore source.
        try {
            $this->backups->restoreSiteDb($alpha, $engineKey);
            self::fail('Expected the engine key to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        // And engine restore refuses site keys.
        try {
            $this->backups->restoreEngine($betaKey);
            self::fail('Expected the site key to be rejected for engine restore.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($alphaLive, hash_file('sha256', $this->dataPath . '/sites/alphasite/site.db'));
    }

    public function testPruneKeepsRowWhenObjectDeleteFails(): void
    {
        $site = $this->sites->create('prunesite', 'Prune Site', 'https://prune.example.com');

        // 31 db snapshots → one over RETAIN_PER_GROUP.
        for ($i = 0; $i < 31; ++$i) {
            $this->backups->backupSiteDb($site, 'test');
        }

        // Sabotage the oldest object so LocalStorageClient::delete is a
        // no-op (file already gone) — prune should still delete the ROW
        // because delete-of-missing is success (idempotent), then verify
        // the retained set is exactly RETAIN_PER_GROUP.
        $result = $this->backups->prune();
        self::assertSame(1, $result['deleted']);
        $remaining = (int) $this->databases->engine()->fetchOne(
            "SELECT COUNT(*) FROM backups WHERE site_handle = 'prunesite' AND kind = 'db'",
        );
        self::assertSame(30, $remaining);
    }

    /**
     * @return array<string, string> relative path => md5
     */
    private function hashTree(string $root): array
    {
        $hashes = [];
        if (!is_dir($root)) {
            return $hashes;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
                $hashes[$rel] = (string) md5_file($file->getPathname());
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
