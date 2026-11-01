<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Header/footer scripts (#13): validation, CRUD, the enabled signal that
 * relaxes CSP/PageAudit, and the bare-URL-vs-verbatim render rule. Backed by
 * a throwaway site DB (the `site_scripts` table ships with the site
 * migrations `SiteService::create` runs).
 */
final class SiteScriptServiceTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteScriptService $scripts;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-scripts-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $sites = new SiteService(new DbalSiteRepository($this->databases, $clock), $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('stest', 'Script Test', 'https://s.example.com');

        $this->scripts = new SiteScriptService($this->databases, $clock);
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

    public function testRejectsBadLocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scripts->save('stest', ['location' => 'sidebar', 'code' => 'x']);
    }

    public function testRejectsBadStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scripts->save('stest', ['location' => 'head', 'strategy' => 'lazy', 'code' => 'x']);
    }

    public function testRejectsOversizedCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scripts->save('stest', ['location' => 'head', 'code' => str_repeat('a', 100_001)]);
    }

    public function testSaveAndList(): void
    {
        $saved = $this->scripts->save('stest', ['label' => 'GA', 'location' => 'head', 'strategy' => 'async', 'code' => 'https://cdn.example/a.js', 'priority' => 10]);
        self::assertGreaterThan(0, $saved->id);
        self::assertSame('head', $saved->location);
        self::assertSame('async', $saved->strategy);

        $list = $this->scripts->list('stest');
        self::assertCount(1, $list);
        self::assertSame('GA', $list[0]->label);
    }

    public function testUpdateInPlaceById(): void
    {
        $saved = $this->scripts->save('stest', ['location' => 'head', 'code' => 'https://cdn.example/a.js']);
        $this->scripts->save('stest', ['id' => $saved->id, 'location' => 'body_end', 'code' => 'https://cdn.example/b.js']);

        $list = $this->scripts->list('stest');
        self::assertCount(1, $list, 'passing an id updates in place, no new row');
        self::assertSame('body_end', $list[0]->location);
        self::assertSame('https://cdn.example/b.js', $list[0]->code);
    }

    public function testHasEnabledToggles(): void
    {
        self::assertFalse($this->scripts->hasEnabled('stest'));
        $this->scripts->save('stest', ['location' => 'head', 'code' => 'x', 'enabled' => true]);
        self::assertTrue($this->scripts->hasEnabled('stest'));
    }

    public function testDisabledScriptIsNeitherEnabledNorRendered(): void
    {
        $this->scripts->save('stest', ['location' => 'head', 'code' => 'https://cdn.example/a.js', 'enabled' => false]);
        self::assertFalse($this->scripts->hasEnabled('stest'));
        self::assertSame('', trim($this->scripts->renderByLocation('stest')['head']));
    }

    public function testDelete(): void
    {
        $saved = $this->scripts->save('stest', ['location' => 'head', 'code' => 'x']);
        $this->scripts->delete('stest', $saved->id);
        self::assertSame([], $this->scripts->list('stest'));
    }

    public function testRenderBareUrlHonorsStrategy(): void
    {
        $this->scripts->save('stest', ['location' => 'head', 'strategy' => 'async', 'code' => 'https://cdn.example/x.js']);
        $this->scripts->save('stest', ['location' => 'body_end', 'strategy' => 'defer', 'code' => 'https://cdn.example/y.js']);

        $out = $this->scripts->renderByLocation('stest');
        self::assertStringContainsString('<script src="https://cdn.example/x.js" async></script>', $out['head']);
        self::assertStringContainsString('<script src="https://cdn.example/y.js" defer></script>', $out['body_end']);
    }

    public function testSnippetWithDataAttributesSurvivesSaveAndRender(): void
    {
        // Embed loaders configure themselves via data-* attributes on their
        // own <script> tag — the full save→DB→render path must be byte-exact.
        $snippet = '<script src="https://widget.example.com/embed/w.js"' . "\n"
            . '        data-widget-key="fa7cb628-aa26-47ac-8973-13d8a8133a8b"' . "\n"
            . '        defer' . "\n"
            . '        crossorigin' . "\n"
            . '        ></script>';
        $this->scripts->save('stest', ['location' => 'head', 'code' => $snippet]);

        $head = $this->scripts->renderByLocation('stest')['head'];
        self::assertStringContainsString($snippet, $head);
        self::assertStringContainsString('data-widget-key="fa7cb628-aa26-47ac-8973-13d8a8133a8b"', $head);
    }

    public function testRenderSnippetIsVerbatim(): void
    {
        $snippet = '<script>window.dataLayer=window.dataLayer||[];</script>';
        $this->scripts->save('stest', ['location' => 'head', 'strategy' => 'blocking', 'code' => $snippet]);

        $head = $this->scripts->renderByLocation('stest')['head'];
        self::assertStringContainsString($snippet, $head, 'markup snippets are injected as-is, not wrapped');
    }
}
