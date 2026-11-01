<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Category\Repository\DbalCategoryRepository;
use Fastsite\Category\Service\CategoryService;
use Fastsite\Category\Service\PermalinkMover;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Categories and permalinks, built for one job: a WordPress site whose posts
 * live at `/{category}/{slug}/` and whose archives live at
 * `/category/{slug}/page/N/` must come across with every URL byte-identical,
 * because the alternative is a redirect for every inbound link.
 *
 * Each test reads the BUILT TREE (or the redirects table), never the intent:
 * a URL that is merely computed correctly but never written, or a redirect
 * that is promised but not stored, is exactly the failure that would go
 * unnoticed until traffic drops.
 */
final class CategoriesTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private CategoryService $categories;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-categories-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $categoryRepo = new DbalCategoryRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $translations = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);

        $permalinks = new PermalinkMover($contentRepo, $categoryRepo, $translations);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo, null, $categoryRepo);
        $this->categories = new CategoryService($categoryRepo, $contentRepo, new ContentSanitizer(), $permalinks);
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot, null, null, $permalinks);

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
            $categoryRepo,
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

    // ------------------------------------------------------------ paths

    public function testAPostLandsAtItsCategoryPathExactly(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'bedst-i-test', ['name' => 'Bedst i test']);
        $this->post($site, 'bedste-myggenet-til-barnevogn', 'bedst-i-test');

        $this->build->build($site);

        $html = $this->file($site, 'bedst-i-test/bedste-myggenet-til-barnevogn/index.html');
        self::assertStringContainsString('<link rel="canonical" href="https://magazine.example/bedst-i-test/bedste-myggenet-til-barnevogn/">', $html);
        self::assertFileDoesNotExist($this->out($site) . '/blog/bedste-myggenet-til-barnevogn/index.html');

        $sitemap = $this->file($site, 'sitemap-da.xml');
        self::assertStringContainsString('<loc>https://magazine.example/bedst-i-test/bedste-myggenet-til-barnevogn/</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://magazine.example/category/bedst-i-test/</loc>', $sitemap, 'archive page 1 is in the sitemap');
    }

    public function testAPostWithNoCategoryDropsTheSegmentRatherThanInventingOne(): void
    {
        $site = $this->wordpressSite();
        $this->post($site, 'loose-post', null);

        $this->build->build($site);

        self::assertFileExists($this->out($site) . '/loose-post/index.html');
        self::assertSame('/loose-post/', SiteLinks::applyPattern('/{category}/{slug}/', 'loose-post', null));
    }

    public function testTheDefaultPatternIsUntouchedForASiteThatNeverUsesCategories(): void
    {
        $this->sites->create('plain', 'Plain', 'https://plain.example');
        $site = $this->siteRepo->findByHandle('plain') ?? self::fail('no site');
        $this->post($site, 'hello', null);

        $this->build->build($site);

        self::assertFileExists($this->out($site) . '/blog/hello/index.html');
        self::assertFileDoesNotExist($this->out($site) . '/category', 'no categories, no archive tree');
    }

    public function testEveryReadSurfaceAgreesWithTheBuiltPath(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'transport', ['name' => 'Transport']);
        $post = $this->post($site, 'barnevogn', 'transport');

        self::assertSame('transport', $post->categorySlug, 'the slug rides along on every read');
        self::assertSame('/transport/barnevogn/', SiteLinks::pathOf($site, $post));
        $api = \Fastsite\Api\V1\ApiHelpers::contentToArray($post, $site);
        self::assertSame('/transport/barnevogn/', $api['path']);
        self::assertSame('transport', $api['category']);

        $this->build->build($site);
        self::assertFileExists($this->out($site) . '/transport/barnevogn/index.html');
    }

    // ------------------------------------------------------------ archives

    public function testArchivesPaginateTheWordPressWayAndEachPageCanonicalsToItself(): void
    {
        $site = $this->wordpressSite(['archive_per_page' => 2]);
        $this->categories->upsert($site, 'baby', ['name' => 'Baby', 'description_html' => '<p>Alt om baby.</p>']);
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $slug) {
            $this->post($site, $slug, 'baby', sprintf('2026-10-0%dT10:00:00Z', $i + 1));
        }

        $this->build->build($site);

        $page1 = $this->file($site, 'category/baby/index.html');
        $page2 = $this->file($site, 'category/baby/page/2/index.html');
        $page3 = $this->file($site, 'category/baby/page/3/index.html');
        self::assertFileDoesNotExist($this->out($site) . '/category/baby/page/4/index.html');

        self::assertStringContainsString('<link rel="canonical" href="https://magazine.example/category/baby/page/2/">', $page2);
        // The intro BLOCK shows on page 1 only (the same text is still every
        // page's meta description, which is right — it describes the archive).
        self::assertStringContainsString('<div class="archive-intro"><p>Alt om baby.</p></div>', $page1);
        self::assertStringNotContainsString('class="archive-intro"', $page2);
        // Newest first, two per page: e d | c b | a.
        self::assertStringContainsString('href="/baby/e/"', $page1);
        self::assertStringContainsString('href="/baby/c/"', $page2);
        self::assertStringContainsString('href="/baby/a/"', $page3);
        self::assertStringNotContainsString('href="/baby/a/"', $page1);
        self::assertStringContainsString('rel="next"', $page1);
        self::assertStringContainsString('href="/category/baby/page/2/"', $page1);
        self::assertStringContainsString('rel="prev"', $page3);

        $sitemap = $this->file($site, 'sitemap-da.xml');
        self::assertStringNotContainsString('/category/baby/page/2/', $sitemap, 'page 1 only');
    }

    public function testACategoryWithNoPublishedPostGetsNoArchive(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'tom', ['name' => 'Tom']);
        $this->content->create($site, ['type' => 'post', 'title' => 'Kladde', 'slug' => 'kladde', 'status' => 'draft', 'category' => 'tom']);

        $this->build->build($site);

        self::assertFileDoesNotExist($this->out($site) . '/category/tom/index.html');
    }

    public function testACustomArchivePatternIsHonoured(): void
    {
        $site = $this->wordpressSite(['category_path_pattern' => '/kategori/{slug}/']);
        $this->categories->upsert($site, 'soevn', ['name' => 'Søvn']);
        $this->post($site, 'sovepose', 'soevn');

        $this->build->build($site);

        self::assertFileExists($this->out($site) . '/kategori/soevn/index.html');
    }

    // ------------------------------------------------------------ crumbs

    public function testThePostTrailIsHomeThenCategoryThenThePost(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'bedst-i-test', ['name' => 'Bedst i test']);
        $this->post($site, 'bedste-loebecykel', 'bedst-i-test');

        $this->build->build($site);
        $html = $this->file($site, 'bedst-i-test/bedste-loebecykel/index.html');

        $crumbs = $this->breadcrumbList($html);
        self::assertSame(['Home', 'Bedst i test', 'Bedste loebecykel'], array_column($crumbs, 'name'));
        self::assertSame('https://magazine.example/category/bedst-i-test/', $crumbs[1]['item']);

        // The visible trail in the default shell: the category links to its
        // archive, the current page is not a link.
        self::assertStringContainsString('class="crumbs"', $html);
        self::assertStringContainsString('<a href="/category/bedst-i-test/"', $html);
        self::assertStringContainsString('<span aria-current="page">Bedste loebecykel</span>', $html);
    }

    public function testAPostWithNoCategoryKeepsTheBlogTrail(): void
    {
        $site = $this->wordpressSite();
        $this->post($site, 'loose', null);

        $this->build->build($site);

        $crumbs = $this->breadcrumbList($this->file($site, 'loose/index.html'));
        self::assertSame(['Home', 'Blog', 'Loose'], array_column($crumbs, 'name'));
    }

    // ------------------------------------------------------------ moves

    public function testMovingAPublishedPostToAnotherCategoryLeavesA301(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);
        $this->categories->upsert($site, 'transport', ['name' => 'Transport']);
        $post = $this->post($site, 'autostol', 'baby');

        $this->content->update($site, $post->id, ['category' => 'transport']);

        self::assertSame(['/baby/autostol/' => '/transport/autostol/'], $this->redirects($site));
    }

    public function testMovingAPostOnASiteWhoseUrlsIgnoreCategoriesRecordsNothing(): void
    {
        $this->sites->create('plain', 'Plain', 'https://plain.example');
        $site = $this->siteRepo->findByHandle('plain') ?? self::fail('no site');
        $this->categories->upsert($site, 'one', ['name' => 'One']);
        $this->categories->upsert($site, 'two', ['name' => 'Two']);
        $post = $this->post($site, 'x', 'one');

        $this->content->update($site, $post->id, ['category' => 'two']);

        self::assertSame([], $this->redirects($site), 'the URL did not move, so there is nothing to redirect');
    }

    public function testRenamingACategoryMovesItsArchiveAndItsPosts(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'graviditet', ['name' => 'Graviditet']);
        $this->post($site, 'kvalme', 'graviditet');

        $result = $this->categories->upsert($site, 'graviditet', ['slug' => 'graviditet-og-amning']);

        self::assertSame(2, $result['redirects']);
        self::assertSame([
            '/category/graviditet/' => '/category/graviditet-og-amning/',
            '/graviditet/kvalme/' => '/graviditet-og-amning/kvalme/',
        ], $this->redirects($site));
    }

    public function testChangingThePostPatternRedirectsPublishedPostsButNotDrafts(): void
    {
        $this->sites->create('mover', 'Mover', 'https://mover.example');
        $site = $this->siteRepo->findByHandle('mover') ?? self::fail('no site');
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);
        $this->post($site, 'live', 'baby');
        $this->content->create($site, ['type' => 'post', 'title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'category' => 'baby']);

        $this->sites->update('mover', ['post_path_pattern' => '/{category}/{slug}/']);

        self::assertSame(['/blog/live/' => '/baby/live/'], $this->redirects($site));
    }

    public function testShorteningTheArchiveSendsItsVanishedPagesToPageOne(): void
    {
        $site = $this->wordpressSite(['archive_per_page' => 1]);
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);
        $this->post($site, 'one', 'baby');
        $this->post($site, 'two', 'baby');

        $this->sites->update($site->handle, ['archive_per_page' => 10]);

        self::assertSame(['/category/baby/page/2/' => '/category/baby/'], $this->redirects($site));
    }

    // ------------------------------------------------------------ guards

    public function testACategoryInUseCannotBeDeleted(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);
        $this->post($site, 'x', 'baby');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('still has 1 post');
        $this->categories->delete($site, 'baby');
    }

    public function testAnUnusedCategoryCanBeDeleted(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'gone', ['name' => 'Gone']);

        $this->categories->delete($site, 'gone');

        self::assertNull($this->categories->find($site, 'gone'));
    }

    public function testAnUnknownCategoryIsRejectedWithTheOnesThatExist(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Existing: baby.');
        $this->post($site, 'x', 'babyy');
    }

    public function testAPageCannotHaveACategory(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'baby', ['name' => 'Baby']);

        $this->expectException(\InvalidArgumentException::class);
        $this->content->create($site, ['type' => 'page', 'title' => 'Om os', 'status' => 'published', 'category' => 'baby']);
    }

    public function testAPostAtTheRootCannotTakeAnEngineDirectoryButOneUnderACategoryCan(): void
    {
        $site = $this->wordpressSite();
        $this->categories->upsert($site, 'guides', ['name' => 'Guides']);

        // No category: the post lands at /media/, on top of the media tree.
        self::assertSame('media-2', $this->post($site, 'media', null)->slug);
        // Under a category the same slug is harmless, and a ported URL must
        // keep it byte for byte.
        self::assertSame('blog', $this->post($site, 'blog', 'guides')->slug);
    }

    public function testACategorySlugIsOneSegment(): void
    {
        $site = $this->wordpressSite();

        $this->expectException(\InvalidArgumentException::class);
        $this->categories->upsert($site, 'baby/toej', ['name' => 'Nested']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badPatterns(): iterable
    {
        yield 'no slug' => ['post_path_pattern', '/{category}/'];
        yield 'two slugs' => ['post_path_pattern', '/{slug}/{slug}/'];
        yield 'no leading slash' => ['post_path_pattern', '{slug}/'];
        yield 'unknown token' => ['post_path_pattern', '/{year}/{slug}/'];
        yield 'uppercase' => ['post_path_pattern', '/Blog/{slug}/'];
        yield 'archive with no fixed segment' => ['category_path_pattern', '/{slug}/'];
        yield 'archive with category token' => ['category_path_pattern', '/{category}/{slug}/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPatterns')]
    public function testMalformedPatternsAreRejected(string $field, string $pattern): void
    {
        $site = $this->wordpressSite();

        $this->expectException(\InvalidArgumentException::class);
        $this->sites->update($site->handle, [$field => $pattern]);
    }

    public function testCategorySettingsAreInTheOutputSignature(): void
    {
        $site = $this->wordpressSite();
        $after = $this->sites->update($site->handle, ['archive_per_page' => 25]);

        self::assertTrue(SiteService::changeNeedsRebuild($site, $after), 'a permalink setting change must republish');
    }

    // ------------------------------------------------------------ helpers

    /**
     * A site configured the way a ported WordPress blog is: posts under
     * their category, archives paginated beneath a fixed segment.
     *
     * @param array<string, mixed> $extra
     */
    private function wordpressSite(array $extra = []): Site
    {
        $this->sites->create('magazine', 'Magazine', 'https://magazine.example', '', 'da');

        return $this->sites->update('magazine', ['post_path_pattern' => '/{category}/{slug}/'] + $extra);
    }

    private function post(Site $site, string $slug, ?string $category, ?string $publishedAt = null): \Fastsite\Content\Dto\Content
    {
        return $this->content->create($site, array_filter([
            'type' => 'post',
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'published',
            'body_html' => '<p>Body.</p>',
            'category' => $category,
            'published_at' => $publishedAt,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /** @return array<string, string> from => to */
    private function redirects(Site $site): array
    {
        $rows = $this->databases->site($site->handle)->fetchAllAssociative('SELECT from_path, to_path FROM redirects ORDER BY from_path');

        return array_column($rows, 'to_path', 'from_path');
    }

    /** @return list<array{name: string, item?: string}> */
    private function breadcrumbList(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'the page carries a graph');
        $graph = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        foreach ($graph['@graph'] as $node) {
            if (($node['@type'] ?? '') === 'BreadcrumbList') {
                return array_map(static fn (array $li): array => array_filter(['name' => $li['name'], 'item' => $li['item'] ?? null]), $node['itemListElement']);
            }
        }
        self::fail('no BreadcrumbList');
    }

    private function out(Site $site): string
    {
        return $this->databases->sitePath($site->handle) . '/build';
    }

    private function file(Site $site, string $relative): string
    {
        $path = $this->out($site) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
