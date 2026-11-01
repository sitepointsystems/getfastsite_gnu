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
 * Structured post fields (intro/tldr/conclusion) + the TOC pipeline.
 *
 * The content_hash backward-compat tests are the guard rail for the ONE
 * change here that could go catastrophically wrong in production: the hash
 * doubles as the translation staleness key, so content without the new
 * fields must keep its byte-identical pre-feature hash — otherwise every
 * translation on every multilingual site is marked stale and retranslated.
 */
final class StructuredFieldsTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private BuildService $build;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-sf-' . bin2hex(random_bytes(6));

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

    public function testHashWithoutFragmentsIsByteIdenticalToTheLegacyFormula(): void
    {
        $site = $this->sites->create('hashcompat', 'Hash Compat', 'https://hc.example.com');

        $post = $this->content->create($site, [
            'type' => 'post',
            'title' => 'Plain post',
            'excerpt' => 'An excerpt.',
            'body_html' => '<p>Body text.</p>',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
            'status' => 'published',
        ]);

        self::assertNull($post->intro);
        self::assertNull($post->tldr);
        self::assertNull($post->conclusion);

        // The literal pre-feature formula, pinned: 5 parts, \x1f-joined.
        $legacy = hash('sha256', implode("\x1f", [
            $post->title, $post->excerpt, $post->bodyHtml, $post->seoTitle, $post->seoDescription,
        ]));
        self::assertSame($legacy, $post->contentHash, 'fragment-less content must keep its pre-feature hash — anything else mass-stales every stored translation');

        // Adding a fragment changes the hash…
        $withTldr = $this->content->update($site, $post->id, ['tldr' => '<p>Short version.</p>']);
        self::assertNotNull($withTldr->tldr);
        self::assertNotSame($legacy, $withTldr->contentHash);

        // …and clearing it (empty string) returns to the exact legacy hash.
        $cleared = $this->content->update($site, $post->id, ['tldr' => '']);
        self::assertNull($cleared->tldr);
        self::assertSame($legacy, $cleared->contentHash);
    }

    public function testFragmentsAreSanitizedLikeTheBody(): void
    {
        $site = $this->sites->create('fragsan', 'Frag San', 'https://fs.example.com');

        $post = $this->content->create($site, [
            'type' => 'post',
            'title' => 'Sanitized',
            'body_html' => '<p>Body.</p>',
            'tldr' => '<p>Summary.</p><script>alert(1)</script>',
            'intro' => '<p style="color:red">Lead.</p>',
            'status' => 'draft',
        ]);

        self::assertStringNotContainsString('<script', (string) $post->tldr);
        self::assertStringNotContainsString('style=', (string) $post->intro);
        self::assertStringContainsString('<p>Summary.</p>', (string) $post->tldr);
        // Fragment warnings carry the field name so authors see WHERE.
        self::assertNotEmpty(array_filter($post->warnings, static fn (string $w): bool => str_starts_with($w, 'tldr:')));

        // Verbatim mode (sanitize:false) wraps fragments like the body.
        $verbatim = $this->content->create($site, [
            'type' => 'post',
            'title' => 'Verbatim',
            'body_html' => '<p>Body.</p>',
            'tldr' => '<svg viewBox="0 0 1 1"></svg>',
            'sanitize' => false,
            'status' => 'draft',
        ]);
        self::assertStringStartsWith('<div data-fs-embed>', (string) $verbatim->tldr);
        self::assertStringContainsString('<svg', (string) $verbatim->tldr);
    }

    public function testBuildRendersFragmentsTocAndScrollSpyWithInlineCsp(): void
    {
        $site = $this->sites->create('tocsite', 'Toc Site', 'https://toc.example.com');

        $this->content->create($site, [
            'type' => 'post',
            'title' => 'Structured post',
            'body_html' => '<p>Opening.</p><h2>Første afsnit</h2><p>a</p><h2>Second Part</h2><p>b</p><h2>Third Part</h2><p>c</p>',
            'tldr' => '<p>The short version.</p>',
            'intro' => '<p>The lead paragraph.</p>',
            'conclusion' => '<p>The closing thought.</p>',
            'status' => 'published',
        ]);

        $this->build->build($site);
        $buildDir = $this->dataPath . '/sites/tocsite/build';
        $html = (string) file_get_contents($buildDir . '/blog/structured-post/index.html');

        // Structured boxes rendered by the shell, outside the body.
        self::assertStringContainsString('The short version.', $html);
        self::assertStringContainsString('article-tldr', $html);
        self::assertStringContainsString('The lead paragraph.', $html);
        self::assertStringContainsString('article-intro', $html);
        self::assertStringContainsString('The closing thought.', $html);
        self::assertStringContainsString('article-conclusion', $html);

        // Heading ids injected (Danish chars follow the slug conventions) and
        // the shell's opt-in TOC nav links them.
        self::assertStringContainsString('id="foerste-afsnit"', $html);
        self::assertStringContainsString('id="second-part"', $html);
        self::assertStringContainsString('data-fs-toc', $html);
        self::assertStringContainsString('href="#foerste-afsnit"', $html);

        // The scroll-spy shipped on THIS page, audit-exempt via data-fs-embed…
        self::assertStringContainsString('requestAnimationFrame', $html);
        self::assertStringContainsString('<div data-fs-embed style="display:none"><script>', $html);

        // …and the site's CSP relaxed to inline-only (never 'full').
        $headers = (string) file_get_contents($buildDir . '/_headers');
        self::assertStringContainsString("script-src 'unsafe-inline'", $headers);
        self::assertStringNotContainsString('unsafe-eval', $headers);

        // Pages without a TOC nav (the home page) carry no spy.
        self::assertStringNotContainsString('requestAnimationFrame', (string) file_get_contents($buildDir . '/index.html'));
    }

    public function testSiteWithoutTocKeepsTheZeroJsCsp(): void
    {
        $site = $this->sites->create('notoc', 'No Toc', 'https://nt.example.com');

        $this->content->create($site, [
            'type' => 'post',
            'title' => 'Short note',
            'body_html' => '<p>No headings here.</p>',
            'tldr' => '<p>Still summarized.</p>',
            'status' => 'published',
        ]);

        $this->build->build($site);
        $buildDir = $this->dataPath . '/sites/notoc/build';

        $html = (string) file_get_contents($buildDir . '/blog/short-note/index.html');
        self::assertStringNotContainsString('data-fs-toc', $html, 'fewer than 3 headings → the bundled shell renders no TOC');
        self::assertStringNotContainsString('requestAnimationFrame', $html);

        self::assertStringContainsString("script-src 'none'", (string) file_get_contents($buildDir . '/_headers'));
    }
}
