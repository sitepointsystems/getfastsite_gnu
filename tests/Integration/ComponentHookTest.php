<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\ComponentRenderer;
use Fastsite\Build\Service\CtaAnnotator;
use Fastsite\Build\Service\HookInjector;
use Fastsite\Component\Repository\DbalComponentRepository;
use Fastsite\Component\Service\ComponentHookService;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Component hooks: site-level rules that inject a component into targeted
 * pages at build time without editing content. Covers the service (CRUD,
 * validation, targeting, staleness signature) and the injected placeholder
 * flowing through the real ComponentRenderer + CtaAnnotator.
 */
final class ComponentHookTest extends TestCase
{
    private string $dataPath;
    private string $appRoot;
    private SiteDatabaseManager $databases;
    private ComponentService $components;
    private ComponentHookService $service;
    private ComponentRenderer $renderer;
    private Site $site;

    protected function setUp(): void
    {
        $this->appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-hook-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $this->appRoot . '/migrations/engine');
        $this->databases->provisionSite('hooktest');
        $migrator->migrate($this->databases->site('hooktest'), $this->appRoot . '/migrations/site');

        $repository = new DbalComponentRepository($this->databases, $clock);
        $this->components = new ComponentService($repository, $this->databases, new NullLogger(), $this->appRoot);
        $this->service = new ComponentHookService($this->databases, $clock, $repository);
        $this->renderer = new ComponentRenderer($repository, $this->databases, new NullLogger(), $this->appRoot);
        $this->site = new Site(1, 'hooktest', 'Hook Test', '', 'https://h.example.com', 'en', [], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');

        $this->components->upsert($this->site, 'cta', [
            'type' => 'html',
            'source_code' => '<aside class="cta"><h3>{{heading}}</h3><a href="/signup">{{button}}</a></aside>',
        ]);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
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
    }

    public function testSaveListUpdateDelete(): void
    {
        $hook = $this->service->save('hooktest', [
            'label' => 'Newsletter CTA',
            'component_handle' => 'cta',
            'values' => ['heading' => 'Join', 'button' => 'Sign up'],
            'position' => 'after_paragraph',
            'paragraph' => 2,
            'target' => 'posts',
            'exclude_slugs' => ['home', 'terms'],
        ]);

        self::assertGreaterThan(0, $hook->id);
        self::assertSame('cta', $hook->componentHandle);
        self::assertSame(2, $hook->paragraph);
        self::assertTrue($hook->enabled);

        $updated = $this->service->save('hooktest', [
            'id' => $hook->id,
            'component_handle' => 'cta',
            'position' => 'bottom',
            'target' => 'all',
            'enabled' => '0',
        ]);
        self::assertSame($hook->id, $updated->id);
        self::assertSame('bottom', $updated->position);
        self::assertFalse($updated->enabled);
        self::assertCount(1, $this->service->list('hooktest'));
        self::assertCount(0, $this->service->list('hooktest', onlyEnabled: true));

        $this->service->delete('hooktest', $hook->id);
        self::assertCount(0, $this->service->list('hooktest'));
    }

    public function testUnknownComponentRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown component/');
        $this->service->save('hooktest', ['component_handle' => 'ghost', 'position' => 'bottom']);
    }

    public function testInvalidPositionAndTargetRejected(): void
    {
        try {
            $this->service->save('hooktest', ['component_handle' => 'cta', 'position' => 'sideways']);
            self::fail('position should have been rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('position', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/target/');
        $this->service->save('hooktest', ['component_handle' => 'cta', 'target' => 'everything']);
    }

    public function testSlugListsNormalizeFromStringsAndPaths(): void
    {
        $hook = $this->service->save('hooktest', [
            'component_handle' => 'cta',
            'include_slugs' => ' /Pricing/ , demo,, pricing ',
            'exclude_slugs' => ['Home', '/terms/'],
        ]);

        self::assertSame(['pricing', 'demo'], $hook->includeSlugs);
        self::assertSame(['home', 'terms'], $hook->excludeSlugs);
    }

    public function testTargetingMatrix(): void
    {
        $hook = $this->service->save('hooktest', [
            'component_handle' => 'cta',
            'target' => 'posts',
            'exclude_slugs' => ['launch'],
        ]);

        self::assertTrue($hook->appliesTo('post', 'my-article'));
        self::assertFalse($hook->appliesTo('page', 'my-article'));
        self::assertFalse($hook->appliesTo('post', 'launch'));

        $pagesOnly = $this->service->save('hooktest', [
            'component_handle' => 'cta',
            'target' => 'pages',
            'include_slugs' => ['pricing'],
            'exclude_slugs' => ['home'],
        ]);
        self::assertTrue($pagesOnly->appliesTo('page', 'pricing'));
        self::assertFalse($pagesOnly->appliesTo('page', 'about'));   // include list narrows
        self::assertFalse($pagesOnly->appliesTo('post', 'pricing')); // wrong type
        self::assertFalse($pagesOnly->appliesTo('page', 'home'));    // exclude wins

        $everything = $this->service->save('hooktest', ['component_handle' => 'cta', 'target' => 'all']);
        self::assertTrue($everything->appliesTo('post', 'x'));
        self::assertTrue($everything->appliesTo('page', 'x'));
    }

    public function testReferencingSeesOnlyEnabledHooks(): void
    {
        self::assertFalse($this->service->referencing('hooktest', 'cta'));

        $hook = $this->service->save('hooktest', ['component_handle' => 'cta']);
        self::assertTrue($this->service->referencing('hooktest', 'cta'));
        self::assertFalse($this->service->referencing('hooktest', 'other'));

        $this->service->save('hooktest', ['id' => $hook->id, 'component_handle' => 'cta', 'enabled' => '0']);
        self::assertFalse($this->service->referencing('hooktest', 'cta'));
    }

    public function testSignatureTracksHooksAndHookedComponentSource(): void
    {
        self::assertSame('', $this->service->signature('hooktest'));

        $hook = $this->service->save('hooktest', ['component_handle' => 'cta']);
        $first = $this->service->signature('hooktest');
        self::assertNotSame('', $first);

        // Editing the hooked COMPONENT must move the signature too — hook
        // pages have no content row to go stale, so this is the only fresh
        // signal preview has.
        $this->components->upsert($this->site, 'cta', [
            'type' => 'html',
            'source_code' => '<aside class="cta v2">{{heading}}</aside>',
        ]);
        $second = $this->service->signature('hooktest');
        self::assertNotSame($first, $second);

        $this->service->save('hooktest', ['id' => $hook->id, 'component_handle' => 'cta', 'enabled' => '0']);
        self::assertSame('', $this->service->signature('hooktest'));
    }

    public function testInjectedHookRendersThroughComponentPipeline(): void
    {
        $hook = $this->service->save('hooktest', [
            'label' => 'Newsletter CTA',
            'component_handle' => 'cta',
            'values' => ['heading' => 'Join us', 'button' => 'Sign up'],
            'position' => 'after_paragraph',
            'paragraph' => 1,
        ]);

        $body = '<p>Intro paragraph.</p><p>Second paragraph.</p>';
        $injected = HookInjector::inject($body, [$hook], 'en');
        $result = $this->renderer->expand('hooktest', $injected, 'en');

        // Static CTA markup right after the first paragraph, placeholder gone.
        self::assertStringContainsString('<p>Intro paragraph.</p><div data-fs-hook="' . $hook->id . '"', $result['html']);
        self::assertStringContainsString('<h3>Join us</h3>', $result['html']);
        self::assertStringNotContainsString('data-fs-component', $result['html']);
        self::assertSame([], $result['warnings']);

        // The CTA's link inherits the hook's data-cta-location, so GTM can
        // report this placement separately from body links.
        $annotated = CtaAnnotator::annotate($result['html'], 'en', ['en']);
        self::assertSame(1, preg_match('/<a\b[^>]*href="\/signup"[^>]*>/', $annotated, $m));
        self::assertStringContainsString('data-cta-location="newsletter_cta"', $m[0]);
    }
}
