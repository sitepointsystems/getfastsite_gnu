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
use Fastsite\Site\Dto\SiteScript;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Scripts that choose their pages, and a CSP that follows them.
 *
 * The point of the whole feature is the NEGATIVE assertion: a tag on one
 * landing page must leave `script-src 'none'` on every page it does not
 * target. Before this, one enabled script relaxed the CSP for the entire site
 * and stripped the zero-JS audit from every page — so a GTM container on
 * /contact/ spent the 100/100/100/100 guarantee on all eighty blog posts,
 * which is the guarantee the product is sold on.
 */
final class SiteScriptTargetingTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private SiteScriptService $scripts;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-sctarget-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);

        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->scripts = new SiteScriptService($this->databases, $clock);
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);

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
            $this->scripts,
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

    // ------------------------------------------------------------- the matcher

    /**
     * The same matrix `ComponentHookTest` pins for hooks, because the two
     * features share one targeting vocabulary and a divergence between them
     * would be invisible until someone wrote a rule that behaved differently
     * depending on which feature read it.
     */
    public function testTargetingMatrix(): void
    {
        $posts = $this->script(['target' => 'posts', 'exclude_slugs' => ['launch']]);
        self::assertTrue($posts->appliesTo('post', 'my-article'));
        self::assertFalse($posts->appliesTo('page', 'my-article'), 'wrong type');
        self::assertFalse($posts->appliesTo('post', 'launch'), 'exclude wins');

        $narrow = $this->script([
            'target' => 'pages',
            'include_slugs' => ['pricing'],
            'exclude_slugs' => ['home'],
        ]);
        self::assertTrue($narrow->appliesTo('page', 'pricing'));
        self::assertFalse($narrow->appliesTo('page', 'about'), 'include list narrows');
        self::assertFalse($narrow->appliesTo('page', 'home'), 'exclude beats include');

        $all = $this->script(['target' => 'all']);
        self::assertTrue($all->appliesTo('post', 'x'));
        self::assertTrue($all->appliesTo('page', 'x'));
    }

    /**
     * The pages with NO content row, which is where this diverges from hooks.
     *
     * `target: all` has to mean literally every page — a tag that silently
     * missed the blog index is a gap nobody notices until a quarter of
     * sessions are unaccounted for — while `posts`/`pages` must not claim
     * them.
     */
    public function testPseudoTypesForThePagesWithNoContentRow(): void
    {
        $all = $this->script(['target' => 'all']);
        self::assertTrue($all->appliesTo('index', 'blog'), 'the blog index counts as a page');
        self::assertTrue($all->appliesTo('error', '404'), 'so does the 404');

        $posts = $this->script(['target' => 'posts']);
        self::assertFalse($posts->appliesTo('index', 'blog'));
        self::assertFalse($posts->appliesTo('error', '404'));

        $pages = $this->script(['target' => 'pages']);
        self::assertFalse($pages->appliesTo('index', 'blog'), 'the blog index is not a `page` row');

        // And they are addressable by slug, so a tag can be kept off them.
        $notBlog = $this->script(['target' => 'all', 'exclude_slugs' => ['blog', '404']]);
        self::assertFalse($notBlog->appliesTo('index', 'blog'));
        self::assertFalse($notBlog->appliesTo('error', '404'));
        self::assertTrue($notBlog->appliesTo('post', 'anything'));
    }

    /** Defaults have to reproduce today's behaviour exactly, or live sites change. */
    public function testTheDefaultIsEveryPage(): void
    {
        $s = $this->script([]);

        self::assertSame('all', $s->target);
        self::assertSame([], $s->includeSlugs);
        self::assertSame([], $s->excludeSlugs);
        self::assertTrue($s->isSiteWide());
        self::assertTrue($s->appliesTo('post', 'x'));
        self::assertTrue($s->appliesTo('index', 'blog'));
    }

    public function testSlugListsNormalizeLikeHooks(): void
    {
        $s = $this->script([
            'include_slugs' => ' /Pricing/ , demo,, pricing ',
            'exclude_slugs' => ['Home', '/terms/'],
        ]);

        self::assertSame(['pricing', 'demo'], $s->includeSlugs, 'trimmed, unslashed, lowercased, deduped');
        self::assertSame(['home', 'terms'], $s->excludeSlugs);
    }

    public function testAnUnknownTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->script(['target' => 'everything']);
    }

    // --------------------------------------------------------------- the build

    public function testATargetedScriptLandsOnlyOnItsPage(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'label' => 'Contact pixel',
            'location' => 'head',
            'code' => '<script src="https://cdn.example/pixel.js"></script>',
            'target' => 'pages',
            'include_slugs' => ['contact'],
        ], $site->handle);

        $this->build->build($site);

        self::assertStringContainsString('cdn.example/pixel.js', $this->read($site, 'contact/index.html'));
        self::assertStringNotContainsString('cdn.example/pixel.js', $this->read($site, 'about/index.html'));
        self::assertStringNotContainsString('cdn.example/pixel.js', $this->read($site, 'blog/a-post/index.html'));
        self::assertStringNotContainsString('cdn.example/pixel.js', $this->read($site, 'index.html'));
    }

    /**
     * THE assertion. Everything else is mechanism; this is the product claim.
     */
    public function testTheRestOfTheSiteKeepsItsZeroJsCsp(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'code' => '<script src="https://cdn.example/pixel.js"></script>',
            'target' => 'pages',
            'include_slugs' => ['contact'],
        ], $site->handle);

        $this->build->build($site);
        $headers = $this->read($site, '_headers');

        self::assertMatchesRegularExpression(
            "~/\\*\n(?:  [^\n]*\n)*?  Content-Security-Policy: script-src 'none'~",
            $headers,
            "the site-wide default stays zero-JS",
        );
        self::assertStringContainsString("/contact/\n  Content-Security-Policy: script-src 'self'", $headers);
        self::assertStringContainsString("/contact/index.html\n  Content-Security-Policy: script-src 'self'", $headers);
        // No rule for a page that got nothing.
        self::assertStringNotContainsString('/about/', $headers);
    }

    /** An inline-only tag earns `inline`, never the `full` relax `src` needs. */
    public function testAnInlineOnlyScriptGetsOnlyTheInlineRelax(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'code' => '<script>window.x=1</script>',
            'target' => 'pages',
            'include_slugs' => ['contact'],
        ], $site->handle);

        $this->build->build($site);
        $headers = $this->read($site, '_headers');

        self::assertStringContainsString("/contact/\n  Content-Security-Policy: script-src 'unsafe-inline'", $headers);
        self::assertStringNotContainsString("script-src 'self'", $headers);
    }

    /**
     * THE regression guard for every site already live.
     *
     * The migration defaults every existing script to `target: all` with empty
     * slug lists, so each one must keep behaving exactly as it did: injected
     * everywhere, CSP relaxed on `/*`, and — this is the part worth asserting
     * in bytes — NOT ONE per-page rule. Getting the baseline backwards would
     * emit a rule per page per locale and blow the `_headers` rule cap on the
     * commonest configuration there is. One real production site has ten
     * enabled scripts across nine locales.
     */
    public function testASiteWideScriptRelaxesTheWholeSiteAndEmitsNoPerPageRules(): void
    {
        $site = $this->seedWithPages();
        $this->script(['code' => '<script src="https://cdn.example/all.js"></script>'], $site->handle);

        $this->build->build($site);
        $headers = $this->read($site, '_headers');

        self::assertStringNotContainsString("script-src 'none'", $headers);
        self::assertStringContainsString('cdn.example/all.js', $this->read($site, 'about/index.html'));
        self::assertStringContainsString('cdn.example/all.js', $this->read($site, 'blog/a-post/index.html'));

        // Exactly the four rules the file has always carried.
        self::assertSame(
            ['/*', '/assets/*', '/media/*', '/files/*'],
            array_values(array_filter(
                preg_split('/\r\n|\n/', $headers) ?: [],
                static fn (string $l): bool => $l !== '' && $l[0] === '/',
            )),
            'a site-wide script must raise the baseline, never emit per-page rules',
        );
    }

    /** `target: all` reaches the pages with no content row, 404 included. */
    public function testASiteWideScriptReachesTheBlogIndexAndThe404(): void
    {
        $site = $this->seedWithPages();
        $this->script(['code' => '<script src="https://cdn.example/all.js"></script>'], $site->handle);

        $this->build->build($site);

        self::assertStringContainsString('cdn.example/all.js', $this->read($site, 'blog/index.html'));
        self::assertStringContainsString('cdn.example/all.js', $this->read($site, '404.html'));
    }

    public function testExcludingTheBlogIndexBySlugWorksEndToEnd(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'code' => '<script src="https://cdn.example/all.js"></script>',
            'target' => 'all',
            'exclude_slugs' => ['blog', '404'],
        ], $site->handle);

        $this->build->build($site);

        self::assertStringNotContainsString('cdn.example/all.js', $this->read($site, 'blog/index.html'));
        self::assertStringNotContainsString('cdn.example/all.js', $this->read($site, '404.html'));
        self::assertStringContainsString('cdn.example/all.js', $this->read($site, 'about/index.html'));
    }

    /** A disabled script is injected nowhere and relaxes nothing. */
    public function testADisabledScriptChangesNothing(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'code' => '<script src="https://cdn.example/off.js"></script>',
            'enabled' => false,
        ], $site->handle);

        $this->build->build($site);

        self::assertStringNotContainsString('cdn.example/off.js', $this->read($site, 'about/index.html'));
        self::assertStringContainsString("script-src 'none'", $this->read($site, '_headers'));
    }

    /**
     * The build must not emit more `_headers` rules than Cloudflare Pages will
     * read. Past the cap it relaxes site-wide and logs — a silently dropped
     * rule would leave a page shipping a script its own policy forbids.
     */
    public function testTooManyPerPageRulesFallsBackToASiteWideRelax(): void
    {
        $site = $this->seedWithPages();
        $slugs = [];
        for ($i = 0; $i < 45; ++$i) {
            $slug = 'lander-' . $i;
            $slugs[] = $slug;
            $this->content->create($site, [
                'type' => 'page', 'title' => 'Lander ' . $i, 'slug' => $slug,
                'status' => 'published', 'body_html' => '<p>Hi.</p>',
            ]);
        }
        // 45 pages × 2 patterns each = 90 rules, over the 60 cap.
        $this->script([
            'code' => '<script src="https://cdn.example/many.js"></script>',
            'target' => 'pages',
            'include_slugs' => $slugs,
        ], $site->handle);

        $this->build->build($site);
        $headers = $this->read($site, '_headers');

        self::assertStringNotContainsString("script-src 'none'", $headers, 'relaxed site-wide instead');
        self::assertStringNotContainsString('/lander-0/', $headers, 'and emits no per-page rules at all');
    }

    // ----------------------------------------------------------- the signature

    /**
     * Changing only the TARGET changes which pages carry the script and not
     * one byte of the rendered bundle — so a signature built from the bundle
     * would not move, and preview would keep serving the old tree. That is the
     * preview-staleness bug this codebase already records five instances of.
     */
    public function testTheSignatureMovesWhenOnlyTheTargetChanges(): void
    {
        $site = $this->seedWithPages();
        $s = $this->script(['code' => '<script>x()</script>'], $site->handle);
        $before = $this->scripts->signature($site->handle);

        $this->scripts->save($site->handle, [
            'id' => $s->id,
            'code' => '<script>x()</script>',
            'location' => $s->location,
            'target' => 'posts',
        ]);

        self::assertNotSame('', $before);
        self::assertNotSame($before, $this->scripts->signature($site->handle));
    }

    public function testTheSignatureIsEmptyWithNoEnabledScripts(): void
    {
        $site = $this->seedWithPages();
        self::assertSame('', $this->scripts->signature($site->handle));

        $this->script(['code' => '<script>x()</script>', 'enabled' => false], $site->handle);
        self::assertSame('', $this->scripts->signature($site->handle), 'disabled is the same as absent');
    }

    // ------------------------------------------------------------- the pattern

    public function testDirectoryIndexesGetBothTheirRequestablePaths(): void
    {
        $site = $this->seedWithPages();
        $this->script([
            'code' => '<script src="https://cdn.example/h.js"></script>',
            'target' => 'pages',
            'include_slugs' => ['home'],
        ], $site->handle);

        $this->build->build($site);
        $headers = $this->read($site, '_headers');

        // `/` and `/index.html` are both real requests for the same page; a
        // policy covering only one of them has a hole in it.
        self::assertStringContainsString("\n/\n  Content-Security-Policy:", $headers);
        self::assertStringContainsString("\n/index.html\n  Content-Security-Policy:", $headers);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string, mixed> $input */
    private function script(array $input, string $handle = 'sctarget'): SiteScript
    {
        if ($handle === 'sctarget' && $this->siteRepo->findByHandle($handle) === null) {
            $this->sites->create($handle, 'Script Target', 'https://sct.example.com');
        }

        return $this->scripts->save($handle, $input + [
            'location' => 'head',
            'code' => '<script>noop()</script>',
        ]);
    }

    private function seedWithPages(string $handle = 'sctarget'): Site
    {
        $this->sites->create($handle, 'Script Target', 'https://sct.example.com');
        $site = $this->siteRepo->findByHandle($handle) ?? self::fail('site vanished');

        foreach (['home', 'contact', 'about'] as $slug) {
            $this->content->create($site, [
                'type' => 'page', 'title' => ucfirst($slug), 'slug' => $slug,
                'status' => 'published', 'body_html' => '<p>Hi.</p>',
            ]);
        }
        $this->content->create($site, [
            'type' => 'post', 'title' => 'A post', 'slug' => 'a-post',
            'status' => 'published', 'body_html' => '<p>Hello.</p>',
        ]);

        return $site;
    }

    private function read(Site $site, string $file): string
    {
        $path = $this->dataPath . '/sites/' . $site->handle . '/build/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
