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
 * Development mode across a real build.
 *
 * Two failure modes are pinned here, and they point in opposite directions.
 * The first is a page that escapes the noindex — and the pages that escape are
 * never the obvious ones, because the blog index and 404 have no content row
 * and so never see the per-page robots flags. The second, and the more
 * expensive one, is a site that stays noindexed after going live; that is why
 * the "off" direction is asserted just as hard as the "on".
 *
 * The third thing pinned is that going live LIFTS the robots.txt block as well
 * as the tags. Development mode closes three doors and every one of them has to
 * reopen; a leftover `Disallow: /` would keep the site out of search on its own
 * while every page looked perfectly indexable.
 */
final class DevelopmentModeTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-dev-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $translationRepo = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);
        $this->build = new BuildService($contentRepo, $this->databases, $clock, new NullLogger(), new \Fastsite\Build\Service\MediaRenderer($mediaRepo), $translationRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot), $mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock), new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)), new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"));
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

    private function seed(string $handle = 'dev'): Site
    {
        $site = $this->sites->create($handle, 'Demo Site', 'https://demo.example.com');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Hello world', 'excerpt' => 'An intro.',
            'body_html' => '<p>Body.</p>', 'status' => 'published',
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'About', 'body_html' => '<p>About us.</p>', 'status' => 'published',
        ]);

        return $site;
    }

    private function out(string $handle, string $path): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/' . $path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * Every .html the build emitted, keyed by its path within build/.
     *
     * @return array<string, string>
     */
    private function pages(string $handle): array
    {
        $root = $this->dataPath . '/sites/' . $handle . '/build';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile() && str_ends_with($file->getFilename(), '.html')) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
                $found[$rel] = (string) file_get_contents($file->getPathname());
            }
        }
        self::assertNotSame([], $found);

        return $found;
    }

    /**
     * A new site is live and indexable. Hiding is the deliberate act, because
     * the asymmetry runs that way: a site nobody remembers to un-hide is a
     * launch that silently never happened, and it looks healthy the whole time.
     * A briefly-visible half-built page costs a day.
     */
    public function testNewSitesStartLive(): void
    {
        self::assertFalse($this->sites->create('fresh', 'Fresh')->development);
    }

    /**
     * The pages with no content row are the ones that leak: the blog index and
     * 404 are rendered straight into the output map and never see the per-page
     * robots flags. Asserting over EVERY emitted page is the point.
     */
    public function testDevelopmentModePutsNoindexOnEverySinglePage(): void
    {
        $site = $this->seed();
        $this->build->build($this->sites->update($site->handle, ['development' => '1']));

        $pages = $this->pages('dev');
        foreach (['index.html', 'blog/index.html', 'blog/hello-world/index.html', 'about/index.html', '404.html'] as $expected) {
            self::assertArrayHasKey($expected, $pages, 'the build emits ' . $expected);
        }
        foreach ($pages as $path => $html) {
            self::assertSame(
                1,
                preg_match_all('#<meta name="robots" content="noindex, nofollow">#', $html),
                $path . ' carries exactly one site-wide robots directive',
            );
        }
    }

    /** The meta tag stops at HTML. A PDF in /files/ needs the header. */
    public function testDevelopmentModeSetsTheRobotsHeaderForNonHtmlToo(): void
    {
        $site = $this->seed();
        $this->build->build($this->sites->update($site->handle, ['development' => '1']));

        self::assertStringContainsString('X-Robots-Tag: noindex, nofollow', $this->out('dev', '_headers'));
    }

    /**
     * A development site is closed to crawlers outright, and stops advertising
     * a sitemap it does not want read.
     *
     * The trade this makes is worth remembering, because the two mechanisms are
     * mutually exclusive rather than additive: a crawler that cannot fetch also
     * cannot read the noindex, so blocking is the right tool for a site that
     * was never indexed and the wrong one for pulling a live site back out of
     * the results. The meta and header stay either way — they are what applies
     * to anything that ignores robots.txt.
     */
    public function testDevelopmentRobotsTxtBlocksEverythingAndDropsTheSitemap(): void
    {
        $site = $this->seed();
        $this->build->build($this->sites->update($site->handle, ['development' => '1']));

        $robots = $this->out('dev', 'robots.txt');
        self::assertStringContainsString('User-agent: *', $robots);
        self::assertStringContainsString('Disallow: /', $robots);
        self::assertStringNotContainsString('Allow: /', $robots);
        self::assertStringNotContainsString('Sitemap:', $robots);
    }

    /**
     * The expensive direction. A site that stays noindexed after launch looks
     * completely healthy and costs months before anyone checks the head.
     */
    public function testGoingLiveTakesTheNoindexOffEveryPageAndRestoresTheSitemap(): void
    {
        $site = $this->seed();
        $this->build->build($this->sites->update($site->handle, ['development' => '1']));
        $live = $this->sites->update($site->handle, ['development' => '0']);
        $this->build->build($live, force: true);

        foreach ($this->pages('dev') as $path => $html) {
            // Error pages are the one exception and always were meant to be:
            // they carry their own permanent noindex, because an indexed 404 is
            // a soft-404 signal Google counts against the whole site. Going
            // live is about un-hiding the pages someone should FIND.
            if (str_ends_with($path, '404.html')) {
                self::assertStringContainsString('noindex', $html, 'a 404 is never indexable, live or not');
                continue;
            }
            self::assertStringNotContainsString('noindex', $html, $path . ' is indexable once live');
        }
        $robots = $this->out('dev', 'robots.txt');
        self::assertStringContainsString('Allow: /', $robots);
        self::assertStringNotContainsString('Disallow:', $robots);
        self::assertStringContainsString('Sitemap: https://demo.example.com/sitemap.xml', $robots);
        self::assertStringNotContainsString('X-Robots-Tag', $this->out('dev', '_headers'));
    }

    /**
     * A page already flagged noindex by its own content row must end up with
     * ONE robots tag, not two. Development mode is strictly more restrictive,
     * so it replaces rather than joins — two robots tags in one head is a
     * contradiction a human then has to resolve.
     */
    public function testAPerPageNoindexIsReplacedRatherThanDuplicated(): void
    {
        $site = $this->seed();
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Secret', 'body_html' => '<p>Hidden.</p>',
            'status' => 'published', 'noindex' => true,
        ]);
        $this->build->build($this->sites->update($site->handle, ['development' => '1']));

        $html = $this->out('dev', 'secret/index.html');
        self::assertSame(1, preg_match_all('#<meta name="robots"#', $html));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    /**
     * Flipping the switch has to republish by itself. If it did not, "go live"
     * would be a setting that changes nothing until something unrelated
     * happened to trigger a build — indistinguishable from broken.
     */
    public function testFlippingDevelopmentIsABuildOutputChange(): void
    {
        $site = $this->seed();
        $on = $this->sites->update($site->handle, ['development' => '1']);
        $off = $this->sites->update($site->handle, ['development' => '0']);

        self::assertTrue(SiteService::changeNeedsRebuild($on, $off));
        self::assertTrue(SiteService::changeNeedsRebuild($off, $on));
    }
}
