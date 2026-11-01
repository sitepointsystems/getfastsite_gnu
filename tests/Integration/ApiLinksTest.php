<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Api\V1\ApiHelpers;
use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The links the read surfaces (REST + MCP) hand back are the links the build
 * emits — checked against a real built tree, not against each other.
 *
 * A URL that is merely plausible is worse than no URL: the caller has no way
 * to tell. So every assertion here ends at a file on disk or at the page's
 * own canonical tag.
 */
final class ApiLinksTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private \Fastsite\Site\Repository\DbalSiteRepository $siteRepo;
    private ContentService $content;
    private MediaService $media;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — link tests need real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-links-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $siteRepo = new \Fastsite\Site\Repository\DbalSiteRepository($this->databases, $clock);
        $this->siteRepo = $siteRepo;
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->media = new MediaService(
            $mediaRepo,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            new \Fastsite\Media\NullImageOptimizer(new NullLogger()),
            $appRoot,
        );

        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
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
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    /**
     * The point of the whole exercise: an agent handed a media url can fetch
     * it. The path must name a file the build actually shipped — which is why
     * the -og crop can never be the answer (it is deliberately not copied).
     */
    public function testMediaUrlNamesAFileTheBuildShipped(): void
    {
        $site = $this->sites->create('links', 'Links', 'https://links.example.com');
        $img = $this->media->ingest($site, $this->pngBytes(1400, 900), 'hero.png', 'a hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'With a hero', 'slug' => 'with-a-hero', 'status' => 'published',
            'body_html' => '<p>Intro.</p><img data-media-id="' . $img->id . '" alt="a hero">',
        ]);
        $this->build->build($site);

        $stored = $this->media->findWithVariants($site->handle, $img->id);
        self::assertNotNull($stored);
        $path = SiteLinks::mediaPath($stored);
        self::assertNotNull($path);
        self::assertFileExists($this->dataPath . '/sites/links/build' . $path);
        self::assertSame('https://links.example.com' . $path, SiteLinks::mediaUrl($site, $stored));

        // And it is the same file the rendered page points its <img src> at.
        $html = (string) file_get_contents($this->dataPath . '/sites/links/build/blog/with-a-hero/index.html');
        self::assertStringContainsString('src="' . $path . '"', $html);

        // Every listed variant is on disk too — the srcset answer, not a guess.
        foreach (SiteLinks::mediaVariants($site, $stored) as $variant) {
            self::assertFileExists($this->dataPath . '/sites/links/build' . $variant['path'], $variant['path']);
        }
    }

    /**
     * A post's url is the page's own canonical. If these two ever disagree,
     * one of them is lying to a search engine.
     */
    public function testContentUrlEqualsTheCanonicalTheBuildEmits(): void
    {
        $site = $this->sites->create('links2', 'Links', 'https://links2.example.com');
        $post = $this->content->create($site, ['type' => 'post', 'title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'body_html' => '<p>Hi.</p>']);
        $page = $this->content->create($site, ['type' => 'page', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body_html' => '<p>Us.</p>']);
        $this->build->build($site);

        self::assertSame(
            $this->canonicalOf('links2', 'blog/hello/index.html'),
            SiteLinks::absolute($site, SiteLinks::contentPath($site, 'post', 'hello')),
        );
        self::assertSame(
            $this->canonicalOf('links2', 'about/index.html'),
            SiteLinks::absolute($site, SiteLinks::contentPath($site, 'page', 'about')),
        );

        // Same answer through the REST serializer, alongside the dates that
        // used to be missing from every list.
        $row = ApiHelpers::contentToArray($post, $site);
        self::assertSame('https://links2.example.com/blog/hello/', $row['url']);
        self::assertSame('/blog/hello/', $row['path']);
        self::assertNotNull($row['published_at']);
        self::assertNotNull($row['updated_at']);

        // No site to resolve against = no URL, rather than a fabricated one.
        self::assertNull(ApiHelpers::contentToArray($page)['url']);
    }

    /** A page slugged `home` publishes at the root, so its url must be the root. */
    public function testHomePageUrlIsTheRootNotSlashHome(): void
    {
        $site = $this->sites->create('links3', 'Links', 'https://links3.example.com');
        $this->content->create($site, ['type' => 'page', 'title' => 'Welcome', 'slug' => 'home', 'status' => 'published', 'body_html' => '<p>Hi.</p>']);
        $this->build->build($site);

        self::assertFileDoesNotExist($this->dataPath . '/sites/links3/build/home/index.html');
        self::assertSame(
            $this->canonicalOf('links3', 'index.html'),
            SiteLinks::absolute($site, SiteLinks::contentPath($site, 'page', 'home')),
        );
    }

    /** A site's own path patterns win — "/blog/{slug}/" is a default, not a rule. */
    public function testCustomPathPatternIsHonoured(): void
    {
        $site = $this->sites->create('links4', 'Links', 'https://links4.example.com');
        $this->databases->engine()->executeStatement(
            'UPDATE sites SET post_path_pattern = ? WHERE handle = ?',
            ['/artikler/{slug}/', 'links4'],
        );
        $site = $this->siteRepo->findByHandle('links4');
        self::assertNotNull($site);
        $this->content->create($site, ['type' => 'post', 'title' => 'Hej', 'slug' => 'hej', 'status' => 'published', 'body_html' => '<p>Hej.</p>']);
        $this->build->build($site);

        self::assertSame('/artikler/hej/', SiteLinks::contentPath($site, 'post', 'hej'));
        self::assertFileExists($this->dataPath . '/sites/links4/build/artikler/hej/index.html');
    }

    private function canonicalOf(string $handle, string $path): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/' . $path;
        self::assertFileExists($file);
        $html = (string) file_get_contents($file);
        self::assertSame(1, preg_match('/<link\b(?=[^>]*\brel="canonical")[^>]*\bhref="([^"]+)"/i', $html, $m), 'no canonical in ' . $path);

        return $m[1];
    }

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 6, 214, 160));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
