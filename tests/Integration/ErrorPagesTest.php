<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Dto\Content;
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
 * Error pages as content rows.
 *
 * Before this existed the 404 was engine boilerplate: its title was the
 * literal English 'Not found' whatever language the site was in, its body was
 * whatever i18n strings the shell happened to ship, and the template was
 * handed no `content` variable at all — so there was no way to put a search
 * box, a CTA or a form on the one page a lost visitor actually sees.
 *
 * The tests that matter most here are the two NEGATIVE ones: an error page
 * must stay out of the sitemap and llms.txt (an indexed 404 is a real
 * soft-404 signal against the whole site), and a site with no row must still
 * get its old shell-rendered 404 — that fallback is what makes the feature
 * additive instead of a migration every existing site has to survive.
 */
final class ErrorPagesTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private DbalContentRepository $contentRepo;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-errpages-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $this->contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);

        $this->content = new ContentService($this->contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        // No ContentService passed: these tests create their own error rows, and
        // a seeded 404 would make "a site with no row falls back" untestable.
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);

        $this->build = new BuildService(
            $this->contentRepo,
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
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    // ------------------------------------------------------------- the row

    public function testTheSlugDefaultsToTheStatusCode(): void
    {
        $site = $this->seed();
        $row = $this->errorRow($site, 404, 'Nothing here');

        // Not a slugified title: "Nothing here" would collide with a real page
        // the moment someone writes one, and the slug does not address the page
        // anyway — SiteLinks sends it to /404.html.
        self::assertSame('404', $row->slug);
        self::assertSame(404, $row->errorStatus);
        self::assertTrue($row->isErrorPage());
    }

    /** An indexed error page is a soft-404 signal, so the flag is not optional. */
    public function testNoindexIsForcedWhateverTheCallerAsked(): void
    {
        $site = $this->seed();
        $row = $this->content->create($site, [
            'type' => 'page', 'status' => 'published', 'error_status' => 404,
            'title' => 'Nope', 'noindex' => false,
        ]);

        self::assertTrue($row->noindex, 'an error page is never indexable, even on request');
        // nofollow is deliberately NOT forced: the "back to the front page"
        // link is exactly the one a stranded crawler should follow.
        self::assertFalse($row->nofollow);
    }

    public function testDetachingReleasesTheNoindexLock(): void
    {
        $site = $this->seed();
        $row = $this->errorRow($site, 403, 'Keep out');

        $after = $this->content->update($site, $row->id, ['error_status' => null, 'noindex' => false]);

        self::assertNull($after->errorStatus);
        self::assertFalse($after->noindex, 'an ordinary page again, so the lock lifts in the same call');
    }

    public function testOneRowPerStatus(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 404, 'First');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already has a 404 page/');
        $this->errorRow($site, 404, 'Second');
    }

    /** A trashed 404 must not block its replacement — hence no unique index. */
    public function testATrashedErrorPageDoesNotBlockANewOne(): void
    {
        $site = $this->seed();
        $first = $this->errorRow($site, 404, 'Old');
        $this->contentRepo->softDelete($site->handle, $first->id);

        $second = $this->errorRow($site, 404, 'New');
        self::assertSame(404, $second->errorStatus);
    }

    public function testAnUnsupportedStatusIsRejectedRatherThanCoerced(): void
    {
        $site = $this->seed();

        // 4004 silently becoming 404 would overwrite the real error page, and
        // silently becoming null would leave the author looking at a page they
        // believe is their 403.
        $this->expectException(\InvalidArgumentException::class);
        $this->errorRow($site, 4004, 'Typo');
    }

    public function testFiveHundredIsNotAFourHundred(): void
    {
        $site = $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $this->errorRow($site, 500, 'Server error');
    }

    // ------------------------------------------------------------- the build

    public function testTheRowsTitleAndBodyReachThePage(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 404, 'This page has moved on', '<p>Try the front page instead.</p>');
        $this->build->build($site);

        $html = $this->read($site, '404.html');
        self::assertStringContainsString('This page has moved on', $html, 'the title comes from the row');
        self::assertStringContainsString('Try the front page instead.', $html, 'and so does the body');
        self::assertStringContainsString('name="robots" content="noindex"', $html);
    }

    public function testItRendersAtTheStatusPathAndNotAtItsSlug(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 404, 'Nope');
        $this->build->build($site);

        self::assertFileExists($this->path($site, '404.html'));
        self::assertFileDoesNotExist($this->path($site, '404/index.html'), 'an error page has no public URL');
    }

    /**
     * The fallback that makes this additive. Every site that existed before
     * error rows did takes this branch, and must be byte-for-byte as it was.
     */
    public function testASiteWithNoRowStillGetsTheShellsOwn404(): void
    {
        $site = $this->seed();
        $this->build->build($site);

        $html = $this->read($site, '404.html');
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('does not exist', $html, 'the shell i18n string, as before');
        // One deliberate change from the old behaviour: the fallback is noindex
        // too. It used to ship indexable, which is a soft-404 signal, and
        // leaving it indexable while a customised 404 is not would be a
        // difference nobody could predict.
        self::assertStringContainsString('name="robots" content="noindex"', $html);
    }

    public function testErrorPagesAreAbsentFromTheSitemapAndLlmsTxt(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 404, 'Unfindable page title');
        $this->build->build($site);

        self::assertStringNotContainsString('404', $this->read($site, 'sitemap-en.xml'));
        self::assertStringNotContainsString('Unfindable page title', $this->read($site, 'llms.txt'));
    }

    /** A second code falls through to error.html.twig, which both shells ship. */
    public function testASecondCodeUsesTheGenericErrorTemplate(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 403, 'No access', '<p>You cannot see this.</p>');
        $this->build->build($site);

        $html = $this->read($site, '403.html');
        self::assertStringContainsString('403', $html, 'the status names itself on the page');
        self::assertStringContainsString('You cannot see this.', $html);
        self::assertStringContainsString('name="robots" content="noindex"', $html);
    }

    /**
     * The whole point of error pages being content rows: a Danish site gets a
     * Danish 404. The engine-generated one said 'Not found' in English on
     * every site in the world.
     */
    public function testASecondaryLocaleGetsItsOwnErrorPage(): void
    {
        $site = $this->seed();
        // addLocale, not update(['locales' => …]) — locales are not an editable
        // site field; adding one is its own operation that also queues the
        // translation job.
        $this->sites->addLocale($site->handle, 'da');
        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');

        $this->errorRow($site, 404, 'Not found here');
        $this->build->build($site);

        // The Danish tree gets a 404 even with no translation stored — an
        // error page falls back to the primary rather than being omitted,
        // because `da/404.html` MUST exist or a Danish miss has nothing to
        // serve at all. Ordinary content takes the opposite rule.
        self::assertFileExists($this->path($site, 'da/404.html'));
        self::assertStringContainsString('Not found here', $this->read($site, 'da/404.html'));
    }

    public function testEveryConfiguredCodeGetsAnErrorDocumentOnApache(): void
    {
        $site = $this->seed();
        $this->errorRow($site, 403, 'No access');
        $this->errorRow($site, 410, 'Gone for good');
        $this->sites->update($site->handle, ['ftp_host' => 'ftp.example.com', 'ftp_username' => 'u']);
        $this->sites->update($site->handle, ['serve_mode' => 'ftp']);
        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');

        $this->build->build($site);
        $ht = $this->read($site, '.htaccess');

        self::assertStringContainsString('ErrorDocument 404 /404.html', $ht);
        self::assertStringContainsString('ErrorDocument 403 /403.html', $ht);
        // The gap this closes: RedirectMatch 410 already fired, but with no
        // ErrorDocument Apache wrapped the right status in the HOST's own body.
        self::assertStringContainsString('ErrorDocument 410 /410.html', $ht);
    }

    /** 404 is wired up with or without a row — the build always emits one. */
    public function testApacheGetsA404ErrorDocumentEvenWithNoRow(): void
    {
        $site = $this->seed();
        $this->sites->update($site->handle, ['ftp_host' => 'ftp.example.com', 'ftp_username' => 'u']);
        $this->sites->update($site->handle, ['serve_mode' => 'ftp']);
        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');

        $this->build->build($site);
        self::assertStringContainsString('ErrorDocument 404 /404.html', $this->read($site, '.htaccess'));
    }

    // ------------------------------------------------------------- the links

    public function testSiteLinksSendsAnErrorRowToItsStatusPath(): void
    {
        $site = $this->seed();

        self::assertSame(
            '/404.html',
            \Fastsite\Shared\SiteLinks::contentPath($site, 'page', '404', '', 404),
        );
        self::assertSame(
            '/da/410.html',
            \Fastsite\Shared\SiteLinks::contentPath($site, 'page', '410', 'da', 410),
        );
        // An ordinary page is untouched by the new argument.
        self::assertSame('/about/', \Fastsite\Shared\SiteLinks::contentPath($site, 'page', 'about'));
    }

    public function testTheCodeListIsFourHundredsOnly(): void
    {
        foreach (Content::CODES as $code) {
            self::assertGreaterThanOrEqual(400, $code);
            self::assertLessThan(500, $code, 'a static host cannot serve a 5xx from a file');
        }
        self::assertContains(404, Content::CODES);
    }

    // ------------------------------------------------------------- helpers

    private function seed(string $handle = 'errsite'): Site
    {
        $this->sites->create($handle, 'Error Site', 'https://err.example.com');
        $site = $this->siteRepo->findByHandle($handle) ?? self::fail('site vanished');
        // One real post, so the blog index and sitemap have something in them
        // and the "absent from" assertions are meaningful.
        $this->content->create($site, [
            'type' => 'post', 'title' => 'A post', 'slug' => 'a-post',
            'status' => 'published', 'body_html' => '<p>Hello.</p>',
        ]);

        return $site;
    }

    private function errorRow(Site $site, int $status, string $title, string $body = '<p>Body.</p>'): Content
    {
        return $this->content->create($site, [
            'type' => 'page',
            'status' => 'published',
            'error_status' => $status,
            'title' => $title,
            'body_html' => $body,
        ]);
    }

    private function path(Site $site, string $file): string
    {
        return $this->dataPath . '/sites/' . $site->handle . '/build/' . $file;
    }

    private function read(Site $site, string $file): string
    {
        $path = $this->path($site, $file);
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
