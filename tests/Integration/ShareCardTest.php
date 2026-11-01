<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A page's featured image is its share card.
 *
 * Every built page used to carry the SITE's default card as og:image and
 * twitter:image, even when the page had a featured image of its own — so
 * every link to the site looked identical wherever it was pasted, which on a
 * content site is most of the reason anyone clicks. The 1200x630 crop already
 * existed on disk for every image; `copyMedia` just refused to ship any of
 * them except the site card, and nothing exposed a per-page one to the shell.
 *
 * These assertions read the EMITTED HTML and then check the file is really in
 * the build output, because the two halves fail independently: a correct URL
 * pointing at a crop that was never copied is a blank card, and a shipped crop
 * nothing references is dist bloat.
 */
final class ShareCardTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private MediaService $media;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — needs real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-share-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);

        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->media = new MediaService($mediaRepo, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);

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
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->dataPath);
    }

    /**
     * The headline case: a post with a featured image gets ITS OWN crop, as an
     * absolute URL, and the file is in the build output. Social scrapers do not
     * resolve root-relative paths, so a relative URL here is a blank card.
     */
    public function testAPostWithAFeaturedImageSharesItsOwnCrop(): void
    {
        $site = $this->seed('shareone');
        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);

        $og = $this->ogImage($this->page('shareone', 'blog/featured/index.html'));

        self::assertStringStartsWith('https://shareone.example.com/', $og, 'scrapers do not resolve relative paths');
        self::assertStringEndsWith('-og.jpg', $og, 'the 1200x630 crop, not a responsive width');
        self::assertStringContainsString('/media/' . $hero->id . '/', $og, 'and it must be THIS page image');
        $this->assertShipped('shareone', $og);
    }

    /**
     * THE CASE THAT MADE THE TWIG ROUTE INSUFFICIENT: a shell that emits no
     * og tags at all. Both bundled shells wrap the block in `{% if share %}`,
     * so a site with no `social_image_media_id` shipped NOTHING — five live
     * sites were measured doing exactly that, home page included, one of them
     * a revenue site. The engine supplies the card now, so the shell's own
     * line is optional everywhere.
     */
    public function testAShellThatEmitsNoOgTagsStillShipsThePagesCard(): void
    {
        $site = $this->seed('sharenone');
        $this->rewriteShellHead('sharenone', static fn (string $t): string => (string) preg_replace(
            // The outer block's `{% endif %}` is the only one alone on its own
            // line — the inner width/height/alt ones sit inline after a tag,
            // so a plain non-greedy match stops at the wrong one.
            ['~\{% if share %\}.*?\n\{% endif %\}~s', '~<meta name="twitter:card"[^>]*>~'],
            '',
            $t,
        ));

        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);

        $html = $this->page('sharenone', 'blog/featured/index.html');
        $og = $this->ogImage($html);

        self::assertStringContainsString('/media/' . $hero->id . '/', $og);
        self::assertStringEndsWith('-og.jpg', $og);
        $this->assertShipped('sharenone', $og);
        self::assertStringContainsString('name="twitter:image" content="' . $og . '"', $html);
        self::assertStringContainsString('<meta property="og:image:alt" content="A hero">', $html);
        // The shell emitted no twitter:card either, and without one X shows a
        // small card — the same wasted picture by another route.
        self::assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
    }

    /**
     * A SHELL CAN BE CONFIDENTLY WRONG, which is why the injection replaces an
     * existing card instead of standing down when it finds one. The measured
     * case emitted `/media/21/photo-…-2048.jpg` — the widest RESPONSIVE jpg
     * rather than the 1200x630 crop: hundreds of KB, wrong aspect ratio,
     * cropped arbitrarily by every scraper. Skipping would have left that site
     * exactly as broken as before, forever.
     */
    public function testAShellsWrongOgImageIsReplacedRatherThanLeftBeside(): void
    {
        $site = $this->seed('sharewrong');
        $this->rewriteShellHead('sharewrong', static fn (string $t): string => str_replace(
            [
                '<meta property="og:image" content="{{ share }}">',
                "{{ share ? 'summary_large_image' : 'summary' }}",
            ],
            [
                '<meta property="og:image" content="{{ site.base_url }}/media/99/wrong-photo-2048.jpg">',
                'summary',
            ],
            $t,
        ));

        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);

        $html = $this->page('sharewrong', 'blog/featured/index.html');

        // Exactly one — two competing og:images is worse than either alone,
        // and `ogImage()` would happily return the first of a pair.
        // The closing quote is required: without it the pattern also counts
        // og:image:width/height/alt, which is three false positives.
        self::assertSame(
            1,
            preg_match_all('~<meta\b[^>]*\b(?:property|name)\s*=\s*["\']og:image["\'][^>]*>~i', $html),
            'the replaced card must not be left beside ours',
        );
        self::assertStringNotContainsString('wrong-photo-2048.jpg', $html, 'the wrong file must be gone, not merely outranked');

        $og = $this->ogImage($html);
        self::assertStringContainsString('/media/' . $hero->id . '/', $og);
        self::assertStringEndsWith('-og.jpg', $og);
        $this->assertShipped('sharewrong', $og);

        // That `summary` was the shell's answer to having no picture.
        self::assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        self::assertStringNotContainsString('content="summary"', $html);
    }

    /** No featured image: the site default, exactly as before this existed. */
    public function testAPageWithoutAFeaturedImageKeepsTheSiteCard(): void
    {
        $site = $this->seed('sharetwo');
        $card = $this->media->ingest($site, $this->image(1200, 630, 'card'), 'card.png', 'Site card');
        $this->sites->update('sharetwo', ['social_image_media_id' => $card->id]);
        $site = $this->reload('sharetwo');

        $this->content->create($site, [
            'type' => 'page', 'title' => 'Plain', 'slug' => 'plain', 'status' => 'published', 'body_html' => '<p>Body.</p>',
        ]);
        $this->build->build($site);

        $og = $this->ogImage($this->page('sharetwo', 'plain/index.html'));

        self::assertStringContainsString('/media/' . $card->id . '/', $og);
        $this->assertShipped('sharetwo', $og);
    }

    /**
     * The blog index has no content row at all, so it has no featured image to
     * take — it must fall through to the site card rather than to nothing.
     */
    public function testTheBlogIndexKeepsTheSiteCard(): void
    {
        $site = $this->seed('sharethree');
        $card = $this->media->ingest($site, $this->image(1200, 630, 'card'), 'card.png', 'Site card');
        $this->sites->update('sharethree', ['social_image_media_id' => $card->id]);
        $site = $this->reload('sharethree');

        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);

        $index = $this->ogImage($this->page('sharethree', 'blog/index.html'));
        self::assertStringContainsString('/media/' . $card->id . '/', $index, 'the index has no row, so no card of its own');

        $post = $this->ogImage($this->page('sharethree', 'blog/featured/index.html'));
        self::assertStringContainsString('/media/' . $hero->id . '/', $post, 'while the post still gets its own');
    }

    /**
     * A translated page has no featured image of its own — the SOURCE row
     * carries it, so every locale shares the primary's card. Getting this wrong
     * would leave every non-primary page on the site card.
     */
    public function testASecondaryLocaleGetsTheSameCard(): void
    {
        $site = $this->seed('sharefour');
        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $post = $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);

        $this->sites->addLocale('sharefour', 'da');
        $site = $this->reload('sharefour');
        $this->databases->site('sharefour')->insert('content_translations', [
            'content_id' => $post->id, 'locale' => 'da', 'title' => 'Fremhaevet', 'slug' => 'fremhaevet',
            'body_html' => '<p>Krop.</p>', 'status' => 'translated',
            'source_hash' => 'x', 'created_at' => '2026-10-20T00:00:00Z', 'updated_at' => '2026-10-20T00:00:00Z',
        ]);
        $this->build->build($site);

        $da = $this->ogImage($this->page('sharefour', 'da/blog/fremhaevet/index.html'));

        self::assertStringContainsString('/media/' . $hero->id . '/', $da);
        $this->assertShipped('sharefour', $da);
    }

    /**
     * `/media/*` is served `immutable` for a year, so a replaced image MUST
     * land under a new filename or caches keep serving the old pixels forever.
     * The responsive variants already did; this pins that the og crop does too
     * (it inherits the `-r{revision}` suffix through the public slug) and that
     * the superseded crop is pruned rather than left to be served.
     */
    public function testAReplacedImageGetsANewCropAndTheOldOneGoes(): void
    {
        $site = $this->seed('sharefive');
        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);
        $before = $this->ogImage($this->page('sharefive', 'blog/featured/index.html'));
        $this->assertShipped('sharefive', $before);

        $this->media->replace($site, $hero->id, $this->image(1400, 800, 'replaced'));
        $this->build->build($site);
        $after = $this->ogImage($this->page('sharefive', 'blog/featured/index.html'));

        self::assertNotSame($before, $after, 'an immutable URL must not hold new bytes');
        self::assertStringContainsString('-r1-og.jpg', $after, 'the revision belongs in the filename');
        $this->assertShipped('sharefive', $after);
        self::assertFileDoesNotExist(
            $this->dataPath . '/sites/sharefive/build' . parse_url($before, PHP_URL_PATH),
            'the superseded crop must be pruned, not left to be served',
        );
    }

    /**
     * Share cards must not depend on the structured-data toggle. They are
     * resolved in the same batched lookup as the JSON-LD image nodes, and that
     * lookup used to be skipped entirely when `structured_data` was off — which
     * would have left those sites on one card for every page, silently.
     */
    public function testTheCardSurvivesStructuredDataBeingOff(): void
    {
        $site = $this->seed('sharesix');
        $this->sites->update('sharesix', ['structured_data' => 0]);
        $site = $this->reload('sharesix');

        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->build->build($site);

        $html = $this->page('sharesix', 'blog/featured/index.html');
        self::assertStringNotContainsString('application/ld+json', $html, 'structured data really is off');
        self::assertStringContainsString('/media/' . $hero->id . '/', $this->ogImage($html));
    }

    /**
     * The restore guarantee: an incremental build over an existing tree must
     * produce exactly what a fresh build produces. Shipping new files
     * conditionally is precisely how that gets broken.
     */
    public function testAnIncrementalBuildEqualsAFreshOne(): void
    {
        $site = $this->seed('shareseven');
        $card = $this->media->ingest($site, $this->image(1200, 630, 'card'), 'card.png', 'Site card');
        $this->sites->update('shareseven', ['social_image_media_id' => $card->id]);
        $site = $this->reload('shareseven');

        $hero = $this->media->ingest($site, $this->image(1600, 900, 'hero'), 'hero.png', 'A hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Featured', 'slug' => 'featured', 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'featured_media_id' => $hero->id,
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Plain', 'slug' => 'plain', 'status' => 'published', 'body_html' => '<p>Body.</p>',
        ]);

        $this->build->build($site);
        $incremental = $this->tree('shareseven');

        // Wipe and rebuild from nothing — the definition of a fresh build.
        $buildDir = $this->dataPath . '/sites/shareseven/build';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        $this->build->build($site, null, force: true);

        self::assertSame($incremental, $this->tree('shareseven'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * A SHELL THE ENGINE CANNOT REACH is the normal case on a live site: every
     * existing site keeps its own `shell/` tree on disk, so the five sites
     * measured emitting no `og:image` at all and the one emitting the wrong
     * file both look exactly like this. Rewriting the installed template is
     * how those two shapes get tested rather than assumed.
     */
    private function rewriteShellHead(string $handle, callable $edit): void
    {
        $file = $this->dataPath . '/sites/' . $handle . '/shell/templates/base.html.twig';
        self::assertFileExists($file);
        $out = $edit((string) file_get_contents($file));
        self::assertIsString($out);
        file_put_contents($file, $out);
    }

    private function seed(string $handle): Site
    {
        return $this->sites->create($handle, 'Share', 'https://' . $handle . '.example.com');
    }

    private function reload(string $handle): Site
    {
        $site = $this->siteRepo->findByHandle($handle);
        self::assertNotNull($site);

        return $site;
    }

    private function page(string $handle, string $relative): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/' . $relative;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private function ogImage(string $html): string
    {
        self::assertSame(
            1,
            preg_match('~<meta property="og:image" content="([^"]+)"~', $html, $m),
            'every page must carry exactly one og:image',
        );

        return $m[1];
    }

    /** A card URL that 404s is worse than none — the caller cannot tell. */
    private function assertShipped(string $handle, string $url): void
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        self::assertFileExists($this->dataPath . '/sites/' . $handle . '/build' . $path, 'the crop has to ship, not just be linked');
    }

    /** @return array<string, string> relative path => sha256 */
    private function tree(string $handle): array
    {
        $root = $this->dataPath . '/sites/' . $handle . '/build';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isFile()) {
                $out[str_replace('\\', '/', substr($entry->getPathname(), \strlen($root) + 1))] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    private function image(int $w, int $h, string $tint): string
    {
        $img = imagecreatetruecolor($w, $h);
        $seed = crc32($tint);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 200, ($seed >> 8) % 200, ($seed >> 16) % 200));
        imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w * 0.6), (int) ($h * 0.6), imagecolorallocate($img, 42, 134, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
