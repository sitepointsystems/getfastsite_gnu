<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

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
 * A rebuild must reflect what is on disk NOW.
 *
 * Every test here runs several builds inside ONE PHP process, because that is
 * the thing that was broken and the thing a `queue:work` worker does all day.
 * Twig names each compiled template class after the loader's cache key and
 * skips recompiling when that class already exists — a process-global check.
 * With FilesystemLoader's path-based key, the first build of a site pinned its
 * templates for the life of the worker: later builds re-used the classes
 * compiled from the OLD source, so shell edits never reached the output, the
 * build reported "0 written", and the publish committed nothing. The pipeline
 * looked green while shipping a stale design, and the only way to shake it
 * loose was previewing (a php-fpm request = a fresh process).
 *
 * A test that builds once per process cannot see any of that, which is exactly
 * why it went unnoticed.
 */
final class RebuildFreshnessTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;
    private DbalSiteRepository $siteRepo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-fresh-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);
        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            $componentRenderer,
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Build\Service\HtaccessBuilder(),
            new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)),
            new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"),
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

    private function seed(string $handle = 'fresh'): Site
    {
        $site = $this->sites->create($handle, 'Fresh Site', 'https://fresh.example.com');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'A post', 'body_html' => '<p>Body.</p>', 'status' => 'published',
        ]);

        return $this->siteRepo->findByHandle($handle) ?? self::fail('site vanished');
    }

    private function templatePath(string $handle = 'fresh'): string
    {
        return $this->dataPath . '/sites/' . $handle . '/shell/templates/base.html.twig';
    }

    private function home(string $handle = 'fresh'): string
    {
        return (string) file_get_contents($this->dataPath . '/sites/' . $handle . '/build/index.html');
    }

    /**
     * Edit a template, rebuild, and the change must ship — three times over,
     * in one process. Before the content-addressed loader, only the first
     * iteration passed.
     */
    public function testEveryTemplateEditReachesTheOutputWithinOneProcess(): void
    {
        $site = $this->seed();
        $template = $this->templatePath();
        $original = (string) file_get_contents($template);

        foreach (['ALPHA', 'BETA', 'GAMMA'] as $marker) {
            file_put_contents($template, str_replace('</body>', '<p>' . $marker . '</p></body>', $original));

            $this->build->build($site);

            self::assertStringContainsString(
                $marker,
                $this->home(),
                'A shell edit must reach the build, including in a process that already built this site.',
            );
        }
    }

    /** Reverting a template must ship the revert, not leave the edit standing. */
    public function testRevertingATemplateShipsTheRevert(): void
    {
        $site = $this->seed();
        $template = $this->templatePath();
        $original = (string) file_get_contents($template);

        file_put_contents($template, str_replace('</body>', '<p>TEMPORARY</p></body>', $original));
        $this->build->build($site);
        self::assertStringContainsString('TEMPORARY', $this->home());

        file_put_contents($template, $original);
        $this->build->build($site);
        self::assertStringNotContainsString('TEMPORARY', $this->home(), 'The revert must reach the output too.');
    }

    /** Content edits were never affected, but they are the other half of "rebuild". */
    public function testContentEditsReachTheOutputWithinOneProcess(): void
    {
        $site = $this->seed();
        $post = $this->content->create($site, [
            'type' => 'page', 'title' => 'Probe', 'body_html' => '<p>ONE</p>', 'status' => 'published',
        ]);
        $this->build->build($site);
        $page = $this->dataPath . '/sites/fresh/build/' . $post->slug . '/index.html';
        self::assertStringContainsString('ONE', (string) file_get_contents($page));

        $this->content->update($site, $post->id, ['body_html' => '<p>TWO</p>']);
        $this->build->build($site);

        $html = (string) file_get_contents($page);
        self::assertStringContainsString('TWO', $html);
        self::assertStringNotContainsString('ONE', $html);
    }

    /**
     * A forced build re-writes every output instead of skipping the ones whose
     * bytes already match. Pressing rebuild should do a rebuild — a run that
     * reports "0 written" because it trusted its own bookkeeping reads exactly
     * like one that silently failed.
     */
    public function testForcedBuildRewritesEverythingWhileTheDefaultStaysIncremental(): void
    {
        $site = $this->seed();

        $this->build->build($site);
        $incremental = $this->build->build($site);
        self::assertSame(0, $incremental->written, 'An unforced rebuild of unchanged content skips everything.');
        self::assertGreaterThan(0, $incremental->skipped);

        $forced = $this->build->build($site, null, force: true);
        self::assertSame(0, $forced->skipped, 'A forced build skips nothing.');
        self::assertSame($incremental->skipped, $forced->written, 'Everything the incremental run skipped is re-written.');
    }

    /** Forcing changes how much is written, never what the bytes are. */
    public function testForcingDoesNotChangeTheOutput(): void
    {
        $site = $this->seed();
        $this->build->build($site, null, force: true);
        $before = $this->home();

        $this->build->build($site, null, force: true);

        self::assertSame($before, $this->home(), 'A forced rebuild must be byte-identical for unchanged input.');
    }
}
