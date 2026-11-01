<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Component\Service\ComponentService;
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
 * A verbatim component through the WHOLE build: the script it ships must
 * reach the built page (PageAudit exempts the data-fs-embed region), and the
 * `_headers` CSP must relax to match — a shipped script the CSP then blocks
 * is a silent break only visible in the browser console. A site with no
 * trusted embeds keeps the zero-JS `script-src 'none'`.
 */
final class ComponentVerbatimBuildTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private ComponentService $components;
    private BuildService $build;
    private DbalSiteRepository $siteRepo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-cvb-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $this->components = new ComponentService($componentRepo, $this->databases, new NullLogger(), $appRoot);
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

    private function seed(string $handle): Site
    {
        $this->sites->create($handle, 'Verbatim Test', 'https://vb.example.com');
        $site = $this->siteRepo->findByHandle($handle) ?? self::fail('site vanished');
        $this->content->create($site, [
            'type' => 'page',
            'title' => 'Widget page',
            'slug' => 'widget-page',
            'body_html' => '<p>Try it:</p><div data-fs-component="calc" data-values="{}"></div>',
            'status' => 'published',
        ]);

        return $site;
    }

    private function built(string $handle, string $file): string
    {
        return (string) file_get_contents($this->dataPath . '/sites/' . $handle . '/build/' . $file);
    }

    public function testVerbatimInlineScriptShipsAndCspRelaxesToInline(): void
    {
        $site = $this->seed('vbinline');
        $this->components->upsert($site, 'calc', [
            'type' => 'html',
            'source_code' => '<div id="calc">…</div><script>document.getElementById("calc").textContent="ready";</script>',
            'verbatim' => true,
        ]);

        $this->build->build($site);

        $page = $this->built('vbinline', 'widget-page/index.html');
        self::assertStringContainsString('textContent="ready"', $page, 'The verbatim script must survive to the built page.');
        self::assertStringContainsString('data-fs-embed', $page);

        $headers = $this->built('vbinline', '_headers');
        self::assertStringContainsString("script-src 'unsafe-inline'", $headers, 'An inline trusted script needs the inline CSP or the browser blocks it.');
    }

    public function testVerbatimExternalScriptRelaxesCspToFull(): void
    {
        $site = $this->seed('vbext');
        $this->components->upsert($site, 'calc', [
            'type' => 'html',
            'source_code' => '<div class="tag"></div><script src="https://widgets.example.com/embed.js" async></script>',
            'verbatim' => true,
        ]);

        $this->build->build($site);

        $headers = $this->built('vbext', '_headers');
        self::assertStringContainsString("'unsafe-eval'", $headers, 'A src-loading trusted embed needs the full CSP (self + https + inline + eval).');
    }

    public function testSiteWithoutTrustedEmbedsKeepsZeroJsCsp(): void
    {
        $site = $this->seed('vbnone');
        // Same component, NOT verbatim: the script is stripped, so the CSP
        // must stay locked down — no relax without a script actually shipped.
        $this->components->upsert($site, 'calc', [
            'type' => 'html',
            'source_code' => '<div id="calc">…</div><script>alert(1)</script>',
        ]);

        $this->build->build($site);

        self::assertStringNotContainsString('<script>alert', $this->built('vbnone', 'widget-page/index.html'));
        self::assertStringContainsString("script-src 'none'", $this->built('vbnone', '_headers'));
    }

    public function testReferencedByContentSeesEmbedsAndIgnoresStrangers(): void
    {
        $site = $this->seed('vbref');
        $this->components->upsert($site, 'calc', ['type' => 'html', 'source_code' => '<b>x</b>']);

        self::assertTrue($this->components->referencedByContent($site, 'calc'));
        self::assertFalse($this->components->referencedByContent($site, 'unused-handle'));
    }
}
