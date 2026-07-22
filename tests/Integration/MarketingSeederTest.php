<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Figlet\FigletRenderer;
use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\ComponentRenderer;
use Fastsite\Build\Service\FileRenderer;
use Fastsite\Build\Service\MediaRenderer;
use Fastsite\Component\Repository\DbalComponentRepository;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\MarketingSeeder;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\Repository\DbalTranslationRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * getfastsite.com dogfood (#4) end to end: the seeder creates the site,
 * installs the shell-marketing theme, authors the marketing pages + launch
 * post through the real ContentService, and builds. Reaching the build
 * assertions proves the marketing shell renders and PageAudit passed (a
 * shipped <script>, external resource, or missing image dimension would make
 * BuildService::build throw). No node/Redis needed — the content has no
 * images or components.
 */
final class MarketingSeederTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private DbalSiteRepository $siteRepo;
    private DbalContentRepository $contentRepo;
    private MarketingSeeder $seeder;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-mkt-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $this->contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);

        $sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $content = new ContentService($this->contentRepo, new ContentSanitizer(), $clock);
        $componentRenderer = new ComponentRenderer(new DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);

        $build = new BuildService(
            $this->contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new MediaRenderer($mediaRepo),
            new DbalTranslationRepository($this->databases, $clock),
            $componentRenderer,
            new FigletRenderer(),
            new FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new SiteScriptService($this->databases, $clock),
        );

        $this->seeder = new MarketingSeeder($sites, $this->siteRepo, $content, $this->contentRepo, $build, $this->databases, $appRoot);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testSeedBuildsTheMarketingSite(): void
    {
        $this->seeder->seed(true);

        // Site provisioned.
        self::assertNotNull($this->siteRepo->findByHandle('getfastsite'));

        // Marketing theme installed; default scaffold replaced (clean reinstall).
        $shell = $this->dataPath . '/sites/getfastsite/shell';
        self::assertFileExists($shell . '/templates/home.html.twig');
        self::assertFileExists($shell . '/assets/style.css');
        self::assertFileDoesNotExist($shell . '/assets/components.css');

        // Content authored + published through the real ContentService.
        foreach (['how-it-works', 'api', 'webhooks'] as $slug) {
            $page = $this->contentRepo->findBySlug('getfastsite', 'page', $slug);
            self::assertNotNull($page, "page '{$slug}' should exist");
            self::assertSame('published', $page->status);
        }
        $post = $this->contentRepo->findBySlug('getfastsite', 'post', 'introducing-fastsite');
        self::assertNotNull($post);
        self::assertSame('published', $post->status);

        // Build output — reaching here means Twig rendered and PageAudit passed.
        $build = $this->dataPath . '/sites/getfastsite/build';
        self::assertFileExists($build . '/index.html');
        self::assertFileExists($build . '/how-it-works/index.html');
        self::assertFileExists($build . '/blog/introducing-fastsite/index.html');
        self::assertStringContainsString('100/100/100/100', (string) file_get_contents($build . '/index.html'));
    }

    public function testSeedIsIdempotent(): void
    {
        $this->seeder->seed(true);
        $before = \count($this->contentRepo->listPublished('getfastsite', 'page'));

        $this->seeder->seed(true);
        $after = \count($this->contentRepo->listPublished('getfastsite', 'page'));

        self::assertSame($before, $after, 're-seeding adds no duplicate content');
    }
}
