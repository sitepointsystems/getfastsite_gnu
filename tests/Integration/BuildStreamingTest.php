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
use Fastsite\Translation\Repository\DbalTranslationRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The build STREAMS: each locale is rendered, finalized, audited and written
 * to disk before the next locale renders, and the heartbeat ticks between
 * every phase. Before this, all locales' pages (plus all locales' translated
 * bodies) accumulated in one array and every post-render pass ran over the
 * whole site with a single heartbeat — on a 10-locale production site the
 * build job died with "Reclaimed after a stale heartbeat" on every attempt
 * (a >5-minute silent stretch, or the worker OOM-killed outright) and
 * dead-lettered without ever shipping the fully translated site.
 *
 * These tests pin the two things the restructure must not break: the
 * heartbeat coverage per locale, and the build_manifest bookkeeping now that
 * it is consumed across several write batches instead of one.
 */
final class BuildStreamingTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;
    private DbalSiteRepository $siteRepo;
    private DbalTranslationRepository $transRepo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-stream-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $this->transRepo = new DbalTranslationRepository($this->databases, $clock);

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
            $this->transRepo,
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

    /**
     * @return array{0: Site, 1: list<int>} the site + its content ids
     */
    private function seedMultilingual(string $handle = 'stream'): array
    {
        $site = $this->sites->create($handle, 'Stream Site', 'https://stream.example.com');
        $this->sites->addLocale($handle, 'da');
        $this->sites->addLocale($handle, 'de');

        $ids = [];
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $post = $this->content->create($site, [
                'type' => 'post',
                'title' => 'Post ' . $slug,
                'slug' => $slug,
                'body_html' => '<p>Body of ' . $slug . '.</p>',
                'excerpt' => 'About ' . $slug . '.',
                'status' => 'published',
            ]);
            $ids[] = $post->id;
            foreach (['da', 'de'] as $locale) {
                $this->transRepo->upsert($handle, $post->id, $locale, [
                    'slug' => $slug . '-' . $locale,
                    'title' => strtoupper($locale) . ' ' . $slug,
                    'excerpt' => 'Om ' . $slug . '.',
                    'body_html' => '<p>' . strtoupper($locale) . ' krop af ' . $slug . '.</p>',
                    'seo_title' => '',
                    'seo_description' => '',
                    'status' => 'manual',
                    'source_hash' => 'seeded',
                ]);
            }
        }

        return [$this->siteRepo->findByHandle($handle) ?? self::fail('site vanished'), $ids];
    }

    /** @return array<string, string> path → sha256 of every file under build/ */
    private function treeHashes(string $handle): array
    {
        $root = $this->dataPath . '/sites/' . $handle . '/build';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($root))), '/');
                $out[$rel] = hash_file('sha256', $file->getPathname()) ?: '';
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The watchdog reclaims a job whose heartbeat is 5 minutes stale, so the
     * build must tick between every phase of every locale — after the
     * expansion chain, after the finalize passes and after the write, at
     * minimum. One tick for the whole post-render stretch is exactly what got
     * the production build reclaimed on every attempt.
     */
    public function testHeartbeatTicksThroughEveryPhaseOfEveryLocale(): void
    {
        [$site] = $this->seedMultilingual();

        $ticks = 0;
        $stats = $this->build->build($site, function () use (&$ticks): void {
            ++$ticks;
        });

        $localeCount = 3; // en + da + de
        self::assertGreaterThanOrEqual(
            3 * $localeCount,
            $ticks,
            'Each locale must heartbeat at least three times (post-expansion, post-finalize, post-write) or a slow locale gets the job falsely reclaimed.',
        );
        self::assertGreaterThan(0, $stats->written);
    }

    /** Every locale's pages must actually land, translated slugs included. */
    public function testEveryLocaleRendersWithItsTranslatedSlugs(): void
    {
        [$site] = $this->seedMultilingual();
        $this->build->build($site);

        $root = $this->dataPath . '/sites/stream/build';
        self::assertFileExists($root . '/blog/alpha/index.html');
        self::assertFileExists($root . '/da/blog/alpha-da/index.html');
        self::assertFileExists($root . '/de/blog/alpha-de/index.html');

        $daPage = (string) file_get_contents($root . '/da/blog/alpha-da/index.html');
        self::assertStringContainsString('DA alpha', $daPage, 'The translated body must render — the per-locale translation load feeds localizeList.');
        self::assertStringContainsString('hreflang="de"', $daPage, 'The hreflang cluster must span locales even though each locale renders in its own batch.');
        self::assertFileExists($root . '/sitemap-da.xml');
        self::assertFileExists($root . '/sitemap.xml');
    }

    /**
     * The build_manifest is now consumed across several write batches (one
     * per locale + the site-level files). An unforced rebuild must still skip
     * everything, and nothing may be mistaken for an orphan just because it
     * was written by a different batch than last time.
     */
    public function testMultilingualRebuildStaysIncrementalAcrossBatches(): void
    {
        [$site] = $this->seedMultilingual();

        $this->build->build($site);
        $second = $this->build->build($site);

        self::assertSame(0, $second->written, 'An unforced rebuild of unchanged content skips every batch.');
        self::assertSame(0, $second->deleted, 'No file written by one batch may be reaped as another batch\'s orphan.');
        self::assertGreaterThan(0, $second->skipped);
    }

    /** Orphan deletion still works when the manifest spans batches. */
    public function testUnpublishedContentIsOrphanedOutOfEveryLocale(): void
    {
        [$site, $ids] = $this->seedMultilingual();
        $this->build->build($site);

        $root = $this->dataPath . '/sites/stream/build';
        self::assertFileExists($root . '/da/blog/alpha-da/index.html');

        $this->content->update($site, $ids[0], ['status' => 'draft']);
        $stats = $this->build->build($site);

        self::assertGreaterThanOrEqual(3, $stats->deleted, 'The unpublished post must leave every locale tree.');
        self::assertFileDoesNotExist($root . '/blog/alpha/index.html');
        self::assertFileDoesNotExist($root . '/da/blog/alpha-da/index.html');
        self::assertFileDoesNotExist($root . '/de/blog/alpha-de/index.html');
    }

    /** Streaming per locale must not change a single output byte. */
    public function testForcedRebuildsAreByteIdenticalOnAMultilingualSite(): void
    {
        [$site] = $this->seedMultilingual();

        $this->build->build($site, null, force: true);
        $first = $this->treeHashes('stream');

        $this->build->build($site, null, force: true);
        self::assertSame($first, $this->treeHashes('stream'), 'Two forced builds of the same inputs must produce identical trees — restores depend on it.');
    }
}
