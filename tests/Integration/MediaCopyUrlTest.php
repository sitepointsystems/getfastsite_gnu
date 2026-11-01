<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Handler\MediaScreenHandler;
use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The media library's "copy url" action.
 *
 * The thing worth pinning is not the button, it is the HONESTY of the button:
 * media ships REFERENCED-ONLY, so an image nothing embeds is pruned from dist
 * and its URL 404s — and a freshly uploaded image is exactly the one someone
 * wants a link for. The screen therefore reads `live` off the built tree, and
 * the URL it offers has to be the file the build actually shipped.
 */
final class MediaCopyUrlTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private MediaService $media;
    private BuildService $build;
    private MediaScreenHandler $screen;
    private int $adminId;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — needs real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-copyurl-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
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

        $users = new UserService($this->databases, $clock);
        $this->adminId = $users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');

        // Redis is lazy and a screen render never touches the session store
        // (the user arrives as a request attribute) — no connection needed.
        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        $dash = new Dash(
            new EngineView($appRoot),
            new SessionService($this->databases, $redis, $clock),
            $siteRepo,
            $this->databases,
            new TeamService($this->databases, $clock),
            new WorkspaceService($this->databases, $clock),
            str_repeat('s', 32),
        );
        $this->screen = new MediaScreenHandler($dash, $this->media, $this->databases);
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

    /**
     * The copied URL is the file the build shipped — asserted against disk,
     * not against another copy of the same string-building logic.
     */
    public function testCopiedUrlIsTheFileTheBuildShipped(): void
    {
        $site = $this->sites->create('copyurl', 'Copy', 'https://copy.example.com');
        $used = $this->media->ingest($site, $this->pngBytes(1400, 900), 'used.png', 'a used hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Uses it', 'slug' => 'uses-it', 'status' => 'published',
            'body_html' => '<p>Hi.</p><img data-media-id="' . $used->id . '" alt="a used hero">',
        ]);
        $this->build->build($site);

        $html = $this->render('copyurl');

        $stored = $this->media->findWithVariants('copyurl', $used->id);
        self::assertNotNull($stored);
        $path = \Fastsite\Shared\SiteLinks::mediaPath($stored);
        self::assertNotNull($path);

        self::assertStringContainsString('data-copy="https://copy.example.com' . $path . '"', $html);
        self::assertFileExists($this->dataPath . '/sites/copyurl/build' . $path);
    }

    /**
     * The whole point. An image nothing embeds is NOT in dist, so its URL is
     * a 404 — the screen has to say so instead of handing over a dead link
     * that looks exactly like a working one.
     */
    public function testUnreferencedImageIsMarkedNotLive(): void
    {
        $site = $this->sites->create('copyurl2', 'Copy', 'https://copy2.example.com');
        $used = $this->media->ingest($site, $this->pngBytes(1200, 800), 'used.png', 'used');
        $unused = $this->media->ingest($site, $this->pngBytes(1100, 700), 'unused.png', 'unused');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Uses one', 'slug' => 'uses-one', 'status' => 'published',
            'body_html' => '<p>Hi.</p><img data-media-id="' . $used->id . '" alt="used">',
        ]);
        $this->build->build($site);

        $usedPath = \Fastsite\Shared\SiteLinks::mediaPath($this->stored('copyurl2', $used->id));
        $unusedPath = \Fastsite\Shared\SiteLinks::mediaPath($this->stored('copyurl2', $unused->id));
        self::assertNotNull($usedPath);
        self::assertNotNull($unusedPath);

        // Ground truth first: this is what referenced-only pruning means.
        self::assertFileExists($this->dataPath . '/sites/copyurl2/build' . $usedPath);
        self::assertFileDoesNotExist($this->dataPath . '/sites/copyurl2/build' . $unusedPath);

        // Both get a button; only the unreferenced one is flagged.
        $html = $this->render('copyurl2');
        self::assertStringContainsString('data-copy="https://copy2.example.com' . $usedPath . '"', $html);
        self::assertStringContainsString('data-copy="https://copy2.example.com' . $unusedPath . '"', $html);
        self::assertSame(1, substr_count($html, 'not live yet'), 'exactly the unreferenced image is flagged');
    }

    /**
     * No base_url = no origin = no public URL. A relative path in someone's
     * clipboard is useless, so the button is absent and the screen says why.
     */
    public function testWithoutABaseUrlThereIsNoCopyButton(): void
    {
        $site = $this->sites->create('copyurl3', 'Copy', '');
        $this->media->ingest($site, $this->pngBytes(900, 600), 'orphan.png', 'orphan');

        $html = $this->render('copyurl3');
        // Matched precisely: the layout's own JS carries a `data-copy="the
        // text"` usage example in a comment, so a bare substring would hit
        // the script block on every page and prove nothing.
        self::assertStringNotContainsString('data-copy="http', $html);
        self::assertStringNotContainsString('>Copy URL<', $html);
        self::assertStringContainsString('to copy public image URLs', $html);
    }

    /**
     * The opt-out from referenced-only publishing. Turning it on is the ONLY
     * thing that gets an unembedded file onto the CDN — republishing alone
     * never will, because the build never copied it.
     */
    public function testPublishAllMediaShipsUnreferencedItemsAndClearsTheMarker(): void
    {
        $site = $this->sites->create('copyurl4', 'Copy', 'https://copy4.example.com');
        $orphan = $this->media->ingest($site, $this->pngBytes(800, 350), 'hero.png', 'an unused hero');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Empty', 'slug' => 'empty', 'status' => 'published', 'body_html' => '<p>Hi.</p>',
        ]);

        $path = \Fastsite\Shared\SiteLinks::mediaPath($this->stored('copyurl4', $orphan->id));
        self::assertNotNull($path);
        $onDisk = $this->dataPath . '/sites/copyurl4/build' . $path;

        // Default: referenced-only. Building twice proves republishing is not
        // the missing ingredient — the file is simply never copied.
        $this->build->build($site);
        $this->build->build($site);
        self::assertFileDoesNotExist($onDisk);
        self::assertStringContainsString('not live yet', $this->render('copyurl4'));

        // The flip is a build-OUTPUT change, so it must republish and it must
        // be visible to preview's staleness signature.
        $after = $this->sites->update('copyurl4', ['publish_all_media' => '1']);
        self::assertTrue($after->publishAllMedia);
        self::assertTrue(\Fastsite\Site\Service\SiteService::changeNeedsRebuild($site, $after));

        $this->build->build($after);
        self::assertFileExists($onDisk);
        self::assertStringNotContainsString('not live yet', $this->render('copyurl4'));

        // And back off again prunes it, so this is not a one-way door.
        $off = $this->sites->update('copyurl4', ['publish_all_media' => '0']);
        $this->build->build($off);
        self::assertFileDoesNotExist($onDisk);
    }

    /**
     * Grid is the default, `?view=` switches AND is remembered in a cookie —
     * without the cookie the choice is lost the moment you follow any link on
     * the screen that carries no `?view=`, which is most of them.
     */
    public function testViewTogglePersistsInACookie(): void
    {
        $site = $this->sites->create('copyurl5', 'Copy', 'https://copy5.example.com');
        $this->media->ingest($site, $this->pngBytes(600, 400), 'a.png', 'a');

        self::assertStringContainsString('class="media-grid"', $this->render('copyurl5'));

        $asked = $this->get('copyurl5', ['view' => 'list']);
        self::assertStringContainsString('class="media-list"', (string) $asked->getBody());
        self::assertStringContainsString('fs_media_view=list', implode(' ', $asked->getHeader('Set-Cookie')));

        // Remembered on the next request, which carries no ?view= at all.
        $remembered = $this->get('copyurl5', [], ['fs_media_view' => 'list']);
        self::assertStringContainsString('class="media-list"', (string) $remembered->getBody());

        // Junk in the cookie falls back to grid rather than erroring, and a
        // value that was never asked for is not written back.
        $junk = $this->get('copyurl5', [], ['fs_media_view' => '../etc']);
        self::assertStringContainsString('class="media-grid"', (string) $junk->getBody());
        self::assertSame([], $junk->getHeader('Set-Cookie'));
    }

    /** Both views offer selection and carry the same actions — one macro. */
    public function testBothViewsOfferSelectionAndTheSameActions(): void
    {
        $site = $this->sites->create('copyurl6', 'Copy', 'https://copy6.example.com');
        $m = $this->media->ingest($site, $this->pngBytes(600, 400), 'a.png', 'a');

        foreach (['grid', 'list'] as $view) {
            $html = (string) $this->get('copyurl6', ['view' => $view])->getBody();
            // The checkbox joins the bulk form BY ATTRIBUTE: cards contain
            // their own alt-edit form and HTML forbids nesting forms.
            self::assertStringContainsString('name="ids[]" value="' . $m->id . '" form="media-bulk"', $html, $view);
            self::assertStringContainsString('value="bulk-delete"', $html, $view);
            self::assertStringContainsString('>Copy URL<', $html, $view);
            self::assertStringContainsString('data-kebab', $html, $view);
            // The locked rule: no browser dialogs anywhere in this UI.
            self::assertStringNotContainsString('return confirm(', $html, $view);

            // The kebab must be a SIBLING of the wrapping action group, not a
            // member of it. As one row with margin-left:auto it was pushed
            // onto a second line the moment an extra item appeared.
            self::assertMatchesRegularExpression(
                '~<div class="acts-main">.*?</div>\s*<div class="kebab">~s',
                $html,
                $view . ': kebab must sit outside the wrapping group',
            );

            // The thumbnail URL has to change when the bytes do. It is cached
            // for five minutes, so without this a replace or a crop looks like
            // it did nothing at all — which is exactly how it was reported.
            self::assertMatchesRegularExpression('~/media/\d+/thumb\?(original=1&amp;)?v=\d+~', $html, $view);
        }
    }

    /**
     * A grid card is ~200px wide: three text buttons plus a kebab cannot fit,
     * so they wrapped and the row looked ragged. The grid therefore carries
     * ONE primary action and the kebab takes the rest; the list keeps them
     * all, because it has the room.
     */
    public function testGridKeepsOneActionAndTheListKeepsThemAll(): void
    {
        $site = $this->sites->create('copyurl8', 'Copy', 'https://copy8.example.com');
        $this->media->ingest($site, $this->pngBytes(900, 600), 'a.png', 'a');

        $grid = (string) $this->get('copyurl8', ['view' => 'grid'])->getBody();
        $list = (string) $this->get('copyurl8', ['view' => 'list'])->getBody();

        // Replace is a button in the list and a menu item in the grid — one
        // control, two homes, never two implementations.
        self::assertStringContainsString('>Replace<', $list);
        self::assertStringNotContainsString('>Replace<', $grid);
        self::assertStringContainsString('Replace file…', $grid);

        // The badge is STATUS and must never be a child of the action row: it
        // is the one item that could not be a button, which is what broke the
        // alignment after optimizing.
        self::assertDoesNotMatchRegularExpression('~<div class="acts-main">(?:(?!</div>).)*opt-badge~s', $grid);
        self::assertDoesNotMatchRegularExpression('~<div class="acts-main">(?:(?!</div>).)*opt-badge~s', $list);
    }

    /**
     * The view switch gets its own toolbar. Tucked into the tabs row between
     * a flex spacer and a muted count it was reported as missing outright,
     * which is a fair verdict on anything that hard to spot.
     */
    public function testTheViewSwitchIsInItsOwnToolbar(): void
    {
        $site = $this->sites->create('copyurl7', 'Copy', 'https://copy7.example.com');
        $this->media->ingest($site, $this->pngBytes(700, 500), 'a.png', 'a');

        $html = $this->render('copyurl7');
        self::assertMatchesRegularExpression('~<div class="viewbar">.*?view=list~s', $html);
        // And not back inside the tabs strip it was lost in. Tempered so the
        // match cannot run past that div's own close — a plain `.*?` reaches
        // the toolbar further down the page and always "matches".
        self::assertDoesNotMatchRegularExpression('~<div class="tabs">(?:(?!</div>).)*viewtabs~s', $html);
    }

    private function stored(string $handle, int $id): \Fastsite\Media\Dto\Media
    {
        $m = $this->media->findWithVariants($handle, $id);
        self::assertNotNull($m);

        return $m;
    }

    private function render(string $handle): string
    {
        return (string) $this->get($handle)->getBody();
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private function get(string $handle, array $query = [], array $cookies = []): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('GET', '/sites/' . $handle . '/media'))
            ->withAttribute(SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $this->adminId, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'admin'])
            ->withAttribute('site', $handle)
            ->withQueryParams($query)
            ->withCookieParams($cookies);

        $response = $this->screen->handle($request);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 42, 134, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
