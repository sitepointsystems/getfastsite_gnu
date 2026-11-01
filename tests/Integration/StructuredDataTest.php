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
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Schema.org JSON-LD across a real build.
 *
 * The load-bearing test here is the one about a shell that never emits the
 * `json_ld` variable: the graph is passed to the template AND injected into
 * the rendered head, and the whole point of the injection is that structured
 * data does not quietly depend on a shell author remembering one line. The
 * matching risk is emitting it TWICE on the shells that do remember, so both
 * directions are pinned.
 */
final class StructuredDataTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-ld-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $translationRepo = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);
        $this->build = new BuildService($contentRepo, $this->databases, $clock, new NullLogger(), new \Fastsite\Build\Service\MediaRenderer($mediaRepo), $translationRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot), $mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock), new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)), new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"));
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

    private function seed(string $handle = 'ld'): \Fastsite\Site\Dto\Site
    {
        $site = $this->sites->create($handle, 'Demo Site', 'https://demo.example.com');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Hello world', 'excerpt' => 'An intro.',
            'body_html' => '<p>Body.</p>', 'status' => 'published', 'author_name' => 'Ada Lovelace',
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'About', 'body_html' => '<p>About us.</p>', 'status' => 'published',
        ]);

        return $site;
    }

    private function out(string $handle, string $path): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/' . $path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * @return array<string, array<string, mixed>> graph nodes keyed by @type
     */
    private function graph(string $html): array
    {
        self::assertSame(1, substr_count($html, 'application/ld+json'), 'exactly one JSON-LD block per page');
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertIsArray($decoded, 'the emitted JSON-LD parses');

        $nodes = [];
        foreach ($decoded['@graph'] as $node) {
            $nodes[(string) $node['@type']] = $node;
        }

        return $nodes;
    }

    public function testEveryPageTypeShipsItsOwnGraph(): void
    {
        $site = $this->seed();
        $this->build->build($site);

        $home = $this->graph($this->out('ld', 'index.html'));
        self::assertSame('https://demo.example.com/#organization', $home['Organization']['@id']);
        self::assertSame('https://demo.example.com/#webpage', $home['WebPage']['@id']);

        $blog = $this->graph($this->out('ld', 'blog/index.html'));
        self::assertArrayHasKey('CollectionPage', $blog);
        self::assertSame('Hello world', $blog['CollectionPage']['mainEntity']['itemListElement'][0]['name']);

        $post = $this->graph($this->out('ld', 'blog/hello-world/index.html'));
        self::assertSame('Hello world', $post['BlogPosting']['headline']);
        self::assertSame('Ada Lovelace', $post['Person']['name']);
        self::assertSame(
            ['Home', 'Blog', 'Hello world'],
            array_column($post['BreadcrumbList']['itemListElement'], 'name'),
        );

        $page = $this->graph($this->out('ld', 'about/index.html'));
        self::assertArrayNotHasKey('BlogPosting', $page);
        self::assertSame(['Home', 'About'], array_column($page['BreadcrumbList']['itemListElement'], 'name'));

        // A 404 describes nothing and has no canonical to anchor @ids on.
        self::assertStringNotContainsString('application/ld+json', $this->out('ld', '404.html'));
    }

    public function testShellThatNeverEmitsTheVariableStillShipsTheGraph(): void
    {
        $site = $this->seed('noldshell');

        // A hand-written shell that simply never heard of `json_ld`. Before the
        // build-time injection this shipped a site with zero structured data
        // and nothing anywhere warned about it.
        $base = $this->dataPath . '/sites/noldshell/shell/templates/base.html.twig';
        $twig = (string) file_get_contents($base);
        self::assertStringContainsString('json_ld', $twig);
        file_put_contents($base, (string) preg_replace('/^.*json_ld.*$\n?/m', '', $twig));

        $this->build->build($site);

        $post = $this->graph($this->out('noldshell', 'blog/hello-world/index.html'));
        self::assertSame('Hello world', $post['BlogPosting']['headline']);
        self::assertArrayHasKey('WebSite', $this->graph($this->out('noldshell', 'index.html')));
    }

    public function testShellThatDoesEmitTheVariableIsNotDoubled(): void
    {
        $site = $this->seed('dupe');
        $base = $this->dataPath . '/sites/dupe/shell/templates/base.html.twig';
        self::assertStringContainsString('json_ld', (string) file_get_contents($base), 'the bundled shell emits it itself');

        $this->build->build($site);

        // graph() asserts exactly one block; this is the assertion that matters.
        $this->graph($this->out('dupe', 'blog/hello-world/index.html'));
        $this->graph($this->out('dupe', 'index.html'));
    }

    public function testBreadcrumbsUseTheShellsOwnLabels(): void
    {
        $site = $this->seed('crumbs');

        // Breadcrumb labels come from the shell i18n file the nav already uses,
        // so a translated site's trail reads in its own language.
        $i18n = $this->dataPath . '/sites/crumbs/shell/i18n/en.json';
        $strings = json_decode((string) file_get_contents($i18n), true);
        $strings['home'] = 'Front';
        $strings['blog'] = 'Journal';
        file_put_contents($i18n, json_encode($strings));

        $this->build->build($site);

        $post = $this->graph($this->out('crumbs', 'blog/hello-world/index.html'));
        self::assertSame(
            ['Front', 'Journal', 'Hello world'],
            array_column($post['BreadcrumbList']['itemListElement'], 'name'),
        );
    }

    public function testSiteSettingsReachTheGraph(): void
    {
        $site = $this->seed('settings');
        $site = $this->sites->update('settings', [
            'schema_type' => 'Person',
            'schema_same_as' => "https://www.linkedin.com/in/demo\nhttps://github.com/demo",
            'meta_description' => 'A demo of the thing.',
        ]);
        $this->build->build($site);

        $home = $this->graph($this->out('settings', 'index.html'));
        self::assertArrayNotHasKey('Organization', $home);
        self::assertSame('Person', $home['Person']['@type']);
        self::assertSame(['https://www.linkedin.com/in/demo', 'https://github.com/demo'], $home['Person']['sameAs']);
        self::assertSame('A demo of the thing.', $home['Person']['description']);
    }

    public function testRelativeProfileUrlIsRejected(): void
    {
        $this->seed('badurl');

        $this->expectException(\InvalidArgumentException::class);
        $this->sites->update('badurl', ['schema_same_as' => '/about']);
    }

    public function testTurningItOffShipsNoStructuredDataAnywhere(): void
    {
        $site = $this->seed('off');
        $site = $this->sites->update('off', ['structured_data' => '0']);
        $this->build->build($site);

        foreach (['index.html', 'blog/index.html', 'blog/hello-world/index.html', 'about/index.html'] as $path) {
            self::assertStringNotContainsString('application/ld+json', $this->out('off', $path), $path);
        }
    }

    public function testStoredContentJsonLdIsMergedRatherThanReplacingThePage(): void
    {
        $site = $this->seed('stored');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Pricing', 'body_html' => '<p>Plans.</p>', 'status' => 'published',
            'json_ld' => '{"@context":"https://schema.org","@type":"Product","name":"Widget"}',
        ]);
        $this->build->build($site);

        $nodes = $this->graph($this->out('stored', 'pricing/index.html'));
        self::assertSame('Widget', $nodes['Product']['name']);
        // The hand-written node joins the graph; it does not evict the entities
        // that make the rest of the page legible to a crawler.
        self::assertArrayHasKey('WebPage', $nodes);
        self::assertArrayHasKey('WebSite', $nodes);
        self::assertArrayHasKey('BreadcrumbList', $nodes);
    }

    public function testSettingsChangesRepublishAndInvalidatePreview(): void
    {
        $before = $this->seed('rebuild');
        $after = $this->sites->update('rebuild', ['schema_same_as' => 'https://github.com/demo']);

        self::assertTrue(SiteService::changeNeedsRebuild($before, $after));
        // The preview staleness signature reads the SAME list, which is what
        // stops a saved setting from republishing while preview serves the old
        // tree — the failure mode every other site-row setting used to have.
        self::assertNotSame(SiteService::outputSignature($before), SiteService::outputSignature($after));
    }
}
