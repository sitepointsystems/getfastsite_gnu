<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Handler\SettingsScreenHandler;
use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\UserService;
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
 * The settings screen is tabbed because it had passed a thousand lines.
 *
 * The tabs are SERVER-SIDE: only the active one renders, and the handler only
 * loads what that tab needs. Splitting a template that size into partials is
 * a slicing exercise, and the way slicing goes wrong is an unbalanced tag or
 * a block landing on no tab at all — so every tab is rendered here, and the
 * cards are counted across the whole set.
 */
final class SettingsTabsTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SettingsScreenHandler $screen;
    private int $adminId;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-tabs-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('tabs', 'Tabs', 'https://tabs.example.com');

        $users = new UserService($this->databases, $clock);
        $this->adminId = $users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');

        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');

        // ShellService needs the publish + build chain even though this screen
        // only ever asks it to list and read files.
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $contentRepo = new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock);
        $translationRepo = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $build = new \Fastsite\Build\Service\BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            $translationRepo,
            new \Fastsite\Build\Service\ComponentRenderer($componentRepo, $this->databases, new NullLogger(), $appRoot),
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Build\Service\HtaccessBuilder(),
            new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, $componentRepo),
            new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), 'https://engine.test'),
        );
        $translations = new \Fastsite\Translation\Service\TranslationService(
            $contentRepo,
            $translationRepo,
            new \Fastsite\Tests\Support\UnusedAiClientFactory(),
            new \Fastsite\Content\Service\ContentSanitizer(),
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $clock),
        );
        $publish = new \Fastsite\Build\Service\PublishTrigger(new \Fastsite\Shared\Queue\JobQueue($this->databases, $redis, $clock), $translations);
        $shell = new \Fastsite\Shell\Service\ShellService($this->databases, $siteRepo, $publish, $build, $clock, new NullLogger());
        $dash = new Dash(
            new EngineView($appRoot),
            new SessionService($this->databases, $redis, $clock),
            $siteRepo,
            $this->databases,
            new TeamService($this->databases, $clock),
            new WorkspaceService($this->databases, $clock),
            str_repeat('s', 32),
        );
        $this->screen = new SettingsScreenHandler(
            $dash,
            $shell,
            $this->databases,
            new \Fastsite\Authoring\AuthoringGuideService(),
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Site\Service\RedirectService($this->databases, $clock),
            new \Fastsite\Publish\Service\GithubCredentialService($this->databases, new \Fastsite\Shared\Crypto\TokenCipher(str_repeat('k', 32)), $clock),
            new TeamService($this->databases, $clock),
            new WorkspaceService($this->databases, $clock),
            new \Fastsite\Ai\AiCredentialService($this->databases, new \Fastsite\Shared\Crypto\TokenCipher(str_repeat('k', 32)), $clock),
            new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock),
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

    /** Every tab renders, and each shows its own cards and nobody else's. */
    public function testEveryTabRendersItsOwnSection(): void
    {
        $expect = [
            'general' => 'Development status',
            'seo' => 'name="action" value="seo-save"',
            'publishing' => 'Go live',
            'email' => 'name="action" value="smtp-save"',
            'scripts' => 'name="action" value="script-save"',
            'redirects' => 'name="action" value="redirect-save"',
            'errors' => 'name="action" value="error-page-create"',
            'shell' => 'Shell snapshots',
        ];

        foreach ($expect as $tab => $needle) {
            $html = $this->get(['tab' => $tab]);
            self::assertStringContainsString($needle, $html, $tab . ' must render its own section');
            // The tab it is on is the active one in the nav.
            self::assertStringContainsString('href="?tab=' . $tab . '" class="active"', $html, $tab . ' nav state');
        }

        // And the long page really is gone: General no longer carries the
        // shell editor or the redirect table.
        $general = $this->get(['tab' => 'general']);
        self::assertStringNotContainsString('Shell snapshots', $general);
        self::assertStringNotContainsString('value="redirect-save"', $general);
        self::assertStringNotContainsString('value="smtp-save"', $general);
    }

    /**
     * Slicing a 1038-line template is where this goes wrong, and the failure
     * mode is a block that lands on NO tab. Every action the handler accepts
     * from this screen must still have a form somewhere.
     */
    public function testNoSettingsFormWentMissingInTheSplit(): void
    {
        $all = '';
        foreach (SettingsScreenHandler::TABS as $tab) {
            $all .= $this->get(['tab' => $tab]);
        }

        foreach ([
            'site-save', 'development', 'publish-all-media', 'seo-save',
            'ftp-save', 'ftp-test', 'smtp-save', 'smtp-test',
            'cf-pages-setup', 'cf-account-id', 'script-save',
            'redirect-save', 'canonical-host', 'shell-snapshot',
            'error-page-create',
        ] as $action) {
            self::assertStringContainsString('value="' . $action . '"', $all, $action . ' lost its form in the split');
        }
    }

    /** Every form carries its tab, or each save would bounce you to General. */
    public function testEveryFormCarriesItsTabBack(): void
    {
        foreach (SettingsScreenHandler::TABS as $tab) {
            $html = $this->get(['tab' => $tab]);
            $forms = substr_count($html, '<form method="post" action="/sites/tabs/settings/action"');
            if ($forms === 0) {
                continue;
            }
            self::assertSame(
                $forms,
                substr_count($html, 'name="tab" value="' . $tab . '"'),
                $tab . ': every settings form must post its tab back',
            );
        }
    }

    /** Unknown and absent both fall back to General, so old links still work. */
    public function testUnknownTabFallsBackToGeneral(): void
    {
        foreach ([[], ['tab' => ''], ['tab' => 'nonsense'], ['tab' => '../etc']] as $query) {
            self::assertStringContainsString('Development status', $this->get($query));
        }
    }

    /** A ?file= link IS the shell tab, wherever it was followed from. */
    public function testOpeningAShellFileLandsOnTheShellTab(): void
    {
        $html = $this->get(['file' => 'templates/base.html.twig']);
        self::assertStringContainsString('Shell snapshots', $html);
        self::assertStringContainsString('href="?tab=shell" class="active"', $html);
    }

    /** @param array<string, string> $query */
    private function get(array $query): string
    {
        $request = (new ServerRequest('GET', '/sites/tabs/settings'))
            ->withAttribute(SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $this->adminId, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'admin'])
            ->withAttribute('site', 'tabs')
            ->withQueryParams($query);

        $response = $this->screen->handle($request);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }
}
