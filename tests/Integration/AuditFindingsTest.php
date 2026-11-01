<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\PageAuditLog;
use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PageAudit has always known what was wrong and had no way to say so: every
 * WARN went to the logger, which writes to a file inside the container, and
 * nowhere else. These tests pin the pipe that fixes that — findings land in
 * the site db on every build, and are read back the way the Audit screen,
 * REST and MCP read them.
 *
 * The build is driven for real rather than calling PageAuditLog by hand wherever
 * possible: a store that works in isolation while nothing ever calls it is
 * precisely the failure this feature exists to end.
 */
final class AuditFindingsTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private BuildService $build;
    private PageAuditLog $auditLog;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-audit-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $translations = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);

        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->auditLog = new PageAuditLog($this->databases, $clock);

        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            $translations,
            new \Fastsite\Build\Service\ComponentRenderer($componentRepo, $this->databases, new NullLogger(), $appRoot),
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Build\Service\HtaccessBuilder(),
            new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, $componentRepo),
            new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), 'https://engine.test'),
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
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testABuildRecordsWhatTheAuditFound(): void
    {
        $site = $this->site();
        $this->post($site, 'hello');

        $this->build->build($site);

        $audit = $this->auditLog->latest($site->handle);
        self::assertNotSame('', $audit['at'], 'the audit carries the timestamp of the build that produced it');
        self::assertNotSame([], $audit['findings'], 'a site with no favicon always has something to say');
        self::assertSame(\count($audit['findings']), $audit['fails'] + $audit['warns'], 'every finding is counted exactly once');

        // The favicon check is site-level and fires for any site without one,
        // which is what makes it a dependable fixture here.
        $messages = implode("\n", array_column($audit['findings'], 'message'));
        self::assertStringContainsString('favicon', $messages);
    }

    public function testEachBuildReplacesTheLastRatherThanAccumulating(): void
    {
        $site = $this->site();
        $this->post($site, 'hello');

        $this->build->build($site);
        $first = $this->auditLog->latest($site->handle);

        $this->build->build($site);
        $second = $this->auditLog->latest($site->handle);

        self::assertSame(
            \count($first['findings']),
            \count($second['findings']),
            'findings describe ONE build — a fixed problem has to disappear, not pile up beside its own fix',
        );
    }

    public function testSiteLevelFindingsSortAboveThePerPageOnes(): void
    {
        $site = $this->site();
        $this->post($site, 'hello');

        $this->build->build($site);

        $findings = $this->auditLog->latest($site->handle)['findings'];
        self::assertNotSame([], $findings);
        self::assertStringStartsWith('(', $findings[0]['page'], 'whole-site findings lead; "(" sorts below every letter');
    }

    public function testAFailIsStoredBeforeTheBuildIsBlocked(): void
    {
        $site = $this->site();
        $this->post($site, 'hello');

        // The failure has to come from the SHELL, not from content: the
        // sanitizer deletes any <img> without a data-media-id precisely so a
        // bare one can never reach the audit, so no body text can trip a
        // pipeline invariant. A <script> in the template can, and that is the
        // realistic case anyway — this is the class of damage an agent
        // designing a shell actually does.
        $base = $this->databases->sitePath($site->handle) . '/shell/templates/base.html.twig';
        self::assertFileExists($base);
        $twig = (string) file_get_contents($base);
        self::assertStringContainsString('</body>', $twig);
        file_put_contents($base, str_replace('</body>', '<script>console.log(1)</script></body>', $twig));

        $blocked = false;
        try {
            $this->build->build($site);
        } catch (\RuntimeException $e) {
            // NOTE: PHPUnit's own AssertionFailedError extends RuntimeException,
            // so never put an assertion inside this try — it would be caught
            // here and the test would pass while proving nothing.
            $blocked = true;
            self::assertStringContainsString('PageAudit', $e->getMessage());
        }
        self::assertTrue($blocked, 'a pipeline-invariant failure must block the build');

        $audit = $this->auditLog->latest($site->handle);
        self::assertGreaterThan(0, $audit['fails'], 'a blocked build still records why — all of it, not just the first');
        self::assertSame('FAIL', $audit['findings'][0]['severity'], 'failures sort above warnings');
    }

    public function testAnUnmigratedSiteIsAnEmptyAuditNotAnError(): void
    {
        // A site whose migrations have not run has no table. Reading it is a
        // blank screen, never a 500 — the same rule TranslationLog follows.
        $audit = $this->auditLog->latest('never-migrated');

        self::assertSame(['at' => '', 'fails' => 0, 'warns' => 0, 'findings' => []], $audit);
    }

    public function testRecordingNeverThrowsOnASiteItCannotWrite(): void
    {
        $this->auditLog->record('never-migrated', [
            ['severity' => 'WARN', 'page' => '(site)', 'message' => 'nowhere to put this'],
        ]);

        self::assertSame([], $this->auditLog->latest('never-migrated')['findings']);
    }

    private function site(): Site
    {
        $this->sites->create('demo', 'Demo', 'https://demo.example');

        return $this->siteRepo->findByHandle('demo') ?? self::fail('no site');
    }

    private function post(Site $site, string $slug): void
    {
        $this->content->create($site, [
            'type' => 'post',
            'title' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'published',
            'body_html' => '<p>Body.</p>',
        ]);
    }
}
