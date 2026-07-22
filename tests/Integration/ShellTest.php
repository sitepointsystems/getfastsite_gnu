<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shell\Service\ShellNotFoundException;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

/**
 * Shell file API: containment guards, manifest, rebuild coalescing,
 * snapshot/restore. Uses Redis (db 15) for the JobQueue.
 */
final class ShellTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private ShellService $shell;
    private DbalSiteRepository $siteRepo;
    private \Fastsite\Site\Dto\Site $site;

    protected function setUp(): void
    {
        try {
            $redis = new Client('tcp://127.0.0.1:6379/15');
            $redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis — shell tests skipped.');
        }
        $redis->flushdb();

        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-shell-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $queue = new JobQueue($this->databases, $redis, $clock);
        $sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->site = $sites->create('shelltest', 'Shell Test', 'https://s.example.com');

        $contentRepo = new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $translationRepo = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), \dirname(__DIR__, 2));
        $build = new \Fastsite\Build\Service\BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            $translationRepo,
            $componentRenderer,
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
        );

        $publish = new \Fastsite\Build\Service\PublishTrigger($queue);
        $this->shell = new ShellService($this->databases, $this->siteRepo, $publish, $build, $clock, new NullLogger());
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

    public function testListReturnsScaffoldedShell(): void
    {
        $files = $this->shell->listFiles($this->site);
        $paths = array_column($files, 'path');

        self::assertContains('templates/base.html.twig', $paths);
        self::assertContains('assets/tokens.css', $paths);
    }

    public function testWriteReadRoundTrip(): void
    {
        $this->shell->writeFile($this->site, 'templates/partials/header.html.twig', '<header>NEW</header>', 'test');

        self::assertSame('<header>NEW</header>', $this->shell->readFile($this->site, 'templates/partials/header.html.twig'));
    }

    public function testWriteBumpsThemeVersionAndQueuesBuild(): void
    {
        $before = $this->siteRepo->findByHandle('shelltest')->themeVersion;
        $this->shell->writeFile($this->site, 'assets/tokens.css', ':root{}', 'test');

        $after = $this->siteRepo->findByHandle('shelltest')->themeVersion;
        self::assertSame($before + 1, $after);

        // A coalesced build job was enqueued (dedupe build:shelltest).
        $jobs = (int) $this->databases->engine()->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE type = 'build_full' AND dedupe_key = 'build:shelltest'",
        );
        self::assertSame(1, $jobs);
    }

    public function testBurstOfWritesCoalescesToOneBuild(): void
    {
        $this->shell->writeFile($this->site, 'assets/tokens.css', ':root{--a:1}', 'test');
        $this->shell->writeFile($this->site, 'assets/components.css', 'body{}', 'test');
        $this->shell->writeFile($this->site, 'templates/base.html.twig', '<html></html>', 'test');

        $jobs = (int) $this->databases->engine()->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE type = 'build_full' AND status = 'pending'",
        );
        self::assertSame(1, $jobs); // three writes, one build
    }

    /**
     * @return list<array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            ['../../etc/passwd'],
            ['templates/../../secret.twig'],
            ['.env'],
            ['templates/.hidden.twig'],
            ['foo.php'],
            ['foo.exe'],
            ["evil\0.twig"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->shell->writeFile($this->site, $path, 'x', 'test');
    }

    public function testDeleteRemovesFileAndManifest(): void
    {
        $this->shell->writeFile($this->site, 'assets/extra.css', 'x{}', 'test');
        $this->shell->deleteFile($this->site, 'assets/extra.css', 'test');

        $this->expectException(ShellNotFoundException::class);
        $this->shell->readFile($this->site, 'assets/extra.css');
    }

    public function testSnapshotAndRestoreRoundTrip(): void
    {
        $this->shell->writeFile($this->site, 'templates/partials/header.html.twig', '<header>V1</header>', 'test');
        $rev = $this->shell->snapshot($this->site, 'v1 snapshot', 'test');

        $this->shell->writeFile($this->site, 'templates/partials/header.html.twig', '<header>V2</header>', 'test');
        self::assertStringContainsString('V2', $this->shell->readFile($this->site, 'templates/partials/header.html.twig'));

        $this->shell->restore($this->site, $rev, 'test');
        self::assertStringContainsString('V1', $this->shell->readFile($this->site, 'templates/partials/header.html.twig'));
    }

    public function testRestoreUnknownRevisionFails(): void
    {
        $this->expectException(ShellNotFoundException::class);
        $this->shell->restore($this->site, 999, 'test');
    }

    public function testDeleteRevisionRemovesArchiveAndRow(): void
    {
        $this->shell->writeFile($this->site, 'templates/partials/header.html.twig', '<header>V1</header>', 'test');
        $keep = $this->shell->snapshot($this->site, 'keep me', 'test');
        $drop = $this->shell->snapshot($this->site, 'drop me', 'test');

        $archive = $this->databases->sitePath($this->site->handle) . '/shell-revisions/rev-' . $drop . '.zip';
        self::assertFileExists($archive);

        $this->shell->deleteRevision($this->site, $drop, 'test');

        self::assertFileDoesNotExist($archive);

        $db = $this->databases->site($this->site->handle);
        $live = array_map('intval', $db->fetchFirstColumn(
            'SELECT rev_no FROM shell_revisions WHERE deleted_at IS NULL ORDER BY rev_no',
        ));
        self::assertSame([$keep], $live, 'the deleted revision should drop out of the live list');

        // The tombstone stays so the number can never be reissued.
        $all = array_map('intval', $db->fetchFirstColumn('SELECT rev_no FROM shell_revisions ORDER BY rev_no'));
        self::assertSame([$keep, $drop], $all);

        // A tombstoned revision must read as absent, not as a broken archive.
        try {
            $this->shell->restore($this->site, $drop, 'test');
            self::fail('restoring a deleted revision should throw');
        } catch (ShellNotFoundException) {
            // expected
        }

        // The surviving snapshot must still restore — deleting a sibling must
        // not disturb the archive another revision points at.
        $this->shell->writeFile($this->site, 'templates/partials/header.html.twig', '<header>V2</header>', 'test');
        $this->shell->restore($this->site, $keep, 'test');
        self::assertStringContainsString('V1', $this->shell->readFile($this->site, 'templates/partials/header.html.twig'));
    }

    public function testDeleteRevisionDoesNotReuseTheNumber(): void
    {
        $first = $this->shell->snapshot($this->site, 'first', 'test');
        $this->shell->deleteRevision($this->site, $first, 'test');

        // MAX(rev_no)+1 over a now-empty table would hand out 1 again, making
        // "restore rev 1" mean two different trees. It must move forward.
        $next = $this->shell->snapshot($this->site, 'second', 'test');
        self::assertGreaterThan($first, $next, 'a deleted revision number must never be reused');
    }

    public function testDeleteUnknownRevisionFails(): void
    {
        $this->expectException(ShellNotFoundException::class);
        $this->shell->deleteRevision($this->site, 999, 'test');
    }

    public function testSandboxViolationInTemplateIsRejected(): void
    {
        // A shell write that would break out of the sandbox (RCE gadget) is
        // rejected at write time — it never lands on disk or reaches a build.
        try {
            $this->shell->writeFile($this->site, 'templates/home.html.twig', "{{ ['id']|map('system')|join }}", 'test');
            self::fail('Expected the malicious template to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('rejected', $e->getMessage());
        }

        // The original (good) home template is intact — the write rolled back.
        self::assertStringContainsString('block', $this->shell->readFile($this->site, 'templates/home.html.twig'));
    }

    public function testTemplateEmittingExternalResourceIsRejected(): void
    {
        // A template that ships an external <script> would FAIL PageAudit and
        // wedge the async build; validation rejects it synchronously instead.
        try {
            $this->shell->writeFile(
                $this->site,
                'templates/partials/footer.html.twig',
                '<footer><script src="https://evil.example/x.js"></script></footer>',
                'test',
            );
            self::fail('Expected the external-resource template to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('rejected', $e->getMessage());
        }
    }
}
