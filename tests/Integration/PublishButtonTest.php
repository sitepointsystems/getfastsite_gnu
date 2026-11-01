<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Dashboard\Handler\ContentActionHandler;
use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Build & publish lives in the site nav, so it fires from every screen.
 *
 * The part worth pinning is the return path. The action used to redirect to
 * Content because Content was the only place it could be pressed from;
 * reachable from everywhere, that would throw away wherever you actually
 * were. And a redirect target read out of a request is a gadget unless it is
 * pinned to somewhere the presser could already reach.
 */
final class PublishButtonTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private ContentActionHandler $handler;
    private int $adminId;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-pub-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('pub', 'Pub', 'https://pub.example.com');
        $sites->create('other', 'Other', 'https://other.example.com');

        $users = new \Fastsite\Identity\Service\UserService($this->databases, $clock);
        $this->adminId = $users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');

        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        $queue = new \Fastsite\Shared\Queue\JobQueue($this->databases, $redis, $clock);
        $contentRepo = new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $translations = new \Fastsite\Translation\Service\TranslationService(
            $contentRepo,
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            new \Fastsite\Tests\Support\UnusedAiClientFactory(),
            new \Fastsite\Content\Service\ContentSanitizer(),
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $clock),
        );
        $dash = new \Fastsite\Dashboard\Dash(
            new \Fastsite\Http\EngineView($appRoot),
            new \Fastsite\Identity\Service\SessionService($this->databases, $redis, $clock),
            $siteRepo,
            $this->databases,
            new \Fastsite\Team\Service\TeamService($this->databases, $clock),
            new \Fastsite\Workspace\Service\WorkspaceService($this->databases, $clock),
            str_repeat('s', 32),
        );
        $ai = new \Fastsite\Tests\Support\UnusedAiClientFactory();
        $contentService = new \Fastsite\Content\Service\ContentService($contentRepo, new \Fastsite\Content\Service\ContentSanitizer(), $clock, $mediaRepo);
        $this->handler = new ContentActionHandler(
            $dash,
            $contentService,
            new \Fastsite\Build\Service\PublishTrigger($queue, $translations),
            new \Fastsite\FactCheck\FactCheckService($ai, $contentRepo, $this->databases, $clock, $contentService),
            $translations,
            $queue,
            new \Fastsite\Category\Service\CategoryService(
                $categoryRepo = new \Fastsite\Category\Repository\DbalCategoryRepository($this->databases, $clock),
                $contentRepo,
                new \Fastsite\Content\Service\ContentSanitizer(),
                new \Fastsite\Category\Service\PermalinkMover($contentRepo, $categoryRepo, new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock)),
            ),
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

    /** The button is in the shared nav, so every site screen carries it. */
    public function testTheNavPartialCarriesThePublishButton(): void
    {
        $nav = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/engine/site-nav.html.twig');

        self::assertStringContainsString('value="publish-site"', $nav);
        self::assertStringContainsString('name="back" value="{{ path }}"', $nav, 'it has to return you where you were');
        // A <form> wrapper would be the flex item instead of the button, so it
        // would size differently from the links beside it.
        self::assertStringContainsString('style="display:contents"', $nav);
    }

    /** Pressed on Media, you come back to Media — not to Content. */
    public function testItReturnsToTheScreenItWasPressedOn(): void
    {
        foreach (['/sites/pub/media', '/sites/pub/settings?tab=email', '/sites/pub/components'] as $from) {
            $response = $this->post(['action' => 'publish-site', 'back' => $from]);
            self::assertSame(302, $response->getStatusCode());
            self::assertStringStartsWith($from, $response->getHeaderLine('Location'), 'returned to ' . $from);
        }
    }

    /**
     * `back` arrives in a request, so it is pinned to this site's own screens.
     * Everything else falls back to Content rather than being followed.
     */
    public function testABackPathOutsideThisSiteIsRefused(): void
    {
        foreach (['/sites/other/media', 'https://evil.example.com', '//evil.example.com', '/admin/users', ''] as $bad) {
            $location = $this->post(['action' => 'publish-site', 'back' => $bad])->getHeaderLine('Location');
            self::assertStringStartsWith('/sites/pub/content', $location, 'refused: ' . $bad);
        }
    }

    /** @param array<string, string> $body */
    private function post(array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('POST', '/sites/pub/content/action'))
            ->withAttribute(SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $this->adminId, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'admin'])
            ->withAttribute('site', 'pub')
            ->withParsedBody($body);

        return $this->handler->handle($request);
    }
}
