<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * The LCP image contract across a real build (needs sharp, like MediaTest).
 *
 * What is pinned here is the failure that shipped 99-performance pages while
 * every engine signal reported them clean: a hero authored in the BODY as
 * <img data-media-id> rendered loading="lazy" with no preload, because the
 * eager+fetchpriority path existed only in the featured_html render — which a
 * shell that lets pages bring their own hero never emits. The featured image
 * must now be prioritised wherever it appears; without a featured id the
 * first hero-sized body image is auto-prioritised; the preload is injected
 * into heads whose shell forgot `page.preload`; and a page with images but no
 * prioritised one WARNs instead of staying silent.
 */
final class LcpPriorityTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private MediaService $media;
    private DbalMediaRepository $mediaRepo;
    private DbalContentRepository $contentRepo;
    private BuildService $build;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — LCP priority tests need real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-lcp-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $siteRepo = new \Fastsite\Site\Repository\DbalSiteRepository($this->databases, $clock);
        $this->contentRepo = new DbalContentRepository($this->databases, $clock);
        $this->mediaRepo = new DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($this->contentRepo, new ContentSanitizer(), $clock, $this->mediaRepo);
        $this->media = new MediaService(
            $this->mediaRepo,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            new \Fastsite\Media\NullImageOptimizer(new NullLogger()),
            $appRoot,
        );

        $records = &$this->logRecords;
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $translationRepo = new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);
        $this->build = new BuildService($this->contentRepo, $this->databases, $clock, $logger, new \Fastsite\Build\Service\MediaRenderer($this->mediaRepo), $translationRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($this->mediaRepo, $this->databases, new NullLogger(), $appRoot), $this->mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock), new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)), new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"));
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

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 6, 214, 160));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function out(string $handle, string $path): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/' . $path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * The LCP-specific PageAudit WARNs only — bare test sites legitimately
     * WARN about missing meta descriptions, which is not under test here.
     *
     * @return list<string>
     */
    private function lcpWarnings(): array
    {
        $out = [];
        foreach ($this->logRecords as $r) {
            if ($r['message'] === 'PageAudit WARN' && str_contains((string) $r['context']['message'], 'no prioritised LCP image')) {
                $out[] = $r['context']['page'] . ': ' . $r['context']['message'];
            }
        }

        return $out;
    }

    private function imagePreloadCount(string $html): int
    {
        return preg_match_all('/<link\b(?=[^>]*\brel="preload")(?=[^>]*\bas="image")[^>]*>/i', $html);
    }

    /**
     * featured_media_id is settable AFTER creation, readable back, validated,
     * and cleared by 0 or null — the write-path gap that made the field
     * unreachable for the life of every edited site.
     */
    public function testFeaturedIdRoundTripsValidatesAndClears(): void
    {
        $site = $this->sites->create('lcp', 'LCP Test', 'https://lcp.example.com');
        $img = $this->media->ingest($site, $this->pngBytes(1400, 900), 'hero.png', 'hero alt');
        $post = $this->content->create($site, ['type' => 'post', 'title' => 'Plain', 'body_html' => '<p>t</p>', 'status' => 'published']);
        self::assertNull($post->featuredMediaId);

        $updated = $this->content->update($site, $post->id, ['featured_media_id' => $img->id]);
        self::assertSame($img->id, $updated->featuredMediaId);

        self::assertNull($this->content->update($site, $post->id, ['featured_media_id' => 0])->featuredMediaId);
        $this->content->update($site, $post->id, ['featured_media_id' => $img->id]);
        self::assertNull($this->content->update($site, $post->id, ['featured_media_id' => null])->featuredMediaId);

        $this->expectException(\InvalidArgumentException::class);
        $this->content->update($site, $post->id, ['featured_media_id' => 99999]);
    }

    /** A media row of the wrong kind (a file, a video) is rejected like a dangling id. */
    public function testFeaturedIdMustBeAnImage(): void
    {
        $site = $this->sites->create('lcpkind', 'Kind Test', 'https://lcpkind.example.com');
        $file = $this->media->ingest($site, "plain text\n", 'notes.txt', '');
        self::assertNotSame('image', $file->kind);
        $post = $this->content->create($site, ['type' => 'post', 'title' => 'Plain', 'body_html' => '<p>t</p>', 'status' => 'published']);

        $this->expectException(\InvalidArgumentException::class);
        $this->content->update($site, $post->id, ['featured_media_id' => $file->id]);
    }

    /**
     * The body-hero shape: the hero lives in the body, the shell never
     * renders featured_html. With featured_media_id set, the body occurrence
     * itself must ship eager + fetchpriority=high and the head must carry
     * exactly ONE image preload (the shell emits page.preload — injection
     * must not double it).
     */
    public function testBodyAuthoredHeroWithFeaturedIdShipsEagerAndOnePreload(): void
    {
        $site = $this->sites->create('lcphero', 'Hero Test', 'https://lcphero.example.com');
        $img = $this->media->ingest($site, $this->pngBytes(1400, 900), 'hero.png', 'hero alt');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Hero page', 'status' => 'published',
            'body_html' => '<img data-media-id="' . $img->id . '" alt="hero alt"><p>Below the hero.</p>',
            'featured_media_id' => $img->id,
        ]);

        $this->build->build($site);
        $html = $this->out('lcphero', 'hero-page/index.html');

        // The bundled shell also renders featured_html, so the same image may
        // legitimately appear twice, BOTH eager — one URL, one fetch. What the
        // engine guarantees is that NO occurrence of the featured image ships
        // lazy, and that the head carries exactly one preload for it.
        self::assertMatchesRegularExpression(
            '#<img src="/media/' . $img->id . '/[^>]*loading="eager" fetchpriority="high"#',
            $html,
        );
        self::assertDoesNotMatchRegularExpression(
            '#<img src="/media/' . $img->id . '/[^>]*loading="lazy"#',
            $html,
            'no occurrence of the featured image ships lazy',
        );
        self::assertSame(1, $this->imagePreloadCount($html), 'exactly one image preload in the page');
        self::assertSame([], $this->lcpWarnings(), 'a prioritised hero page is clean');
    }

    /**
     * No featured id, but the first body image is hero-sized → the build
     * prioritises it on its own. "100/100 by default" is the pitch; the
     * single most common performance failure must not require homework.
     */
    public function testAutoPriorityPicksTheFirstHeroSizedBodyImage(): void
    {
        $site = $this->sites->create('lcpauto', 'Auto Test', 'https://lcpauto.example.com');
        $small = $this->media->ingest($site, $this->pngBytes(320, 240), 'icon.png', 'icon alt');
        $hero = $this->media->ingest($site, $this->pngBytes(1400, 900), 'hero.png', 'hero alt');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Auto page', 'status' => 'published',
            'body_html' => '<img data-media-id="' . $small->id . '" alt="icon alt">'
                . '<img data-media-id="' . $hero->id . '" alt="hero alt"><p>Text.</p>',
        ]);

        $this->build->build($site);
        $html = $this->out('lcpauto', 'auto-page/index.html');

        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
        self::assertSame(1, $this->imagePreloadCount($html));
        // The small first image was passed over, not prioritised.
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertSame([], $this->lcpWarnings());
    }

    /**
     * A page whose LCP is text gains nothing: no preload, no fetchpriority,
     * no WARN. The regression the twelve symptom pages guard against.
     */
    public function testTextOnlyPagesAreUntouched(): void
    {
        $site = $this->sites->create('lcptext', 'Text Test', 'https://lcptext.example.com');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Text page', 'status' => 'published',
            'body_html' => '<p>Words only.</p>',
        ]);

        $this->build->build($site);
        $html = $this->out('lcptext', 'text-page/index.html');

        self::assertSame(0, $this->imagePreloadCount($html));
        self::assertStringNotContainsString('fetchpriority', $html);
        self::assertSame([], $this->lcpWarnings());
    }

    /**
     * Images on the page but nothing prioritised (all below hero size, no
     * featured id) = the page almost certainly ships a lazy LCP. That must
     * say so at build time — silence is how this reached production.
     */
    public function testUnprioritizedImagePageWarns(): void
    {
        $site = $this->sites->create('lcpwarn', 'Warn Test', 'https://lcpwarn.example.com');
        $small = $this->media->ingest($site, $this->pngBytes(480, 320), 'photo.png', 'photo alt');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Small images', 'status' => 'published',
            'body_html' => '<img data-media-id="' . $small->id . '" alt="photo alt"><p>Text.</p>',
        ]);

        $this->build->build($site);

        $warnings = $this->lcpWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('small-images/index.html', $warnings[0]);
        self::assertStringContainsString('no prioritised LCP image', $warnings[0]);
    }

    /**
     * A hand-written shell that never emits `page.preload` still ships the
     * preload — injected into the head, exactly like the JSON-LD graph. LCP
     * request discovery must never hinge on one shell line.
     */
    public function testPreloadIsInjectedWhenTheShellOmitsIt(): void
    {
        $site = $this->sites->create('lcpinject', 'Inject Test', 'https://lcpinject.example.com');
        $img = $this->media->ingest($site, $this->pngBytes(1400, 900), 'hero.png', 'hero alt');
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Hero page', 'status' => 'published',
            'body_html' => '<img data-media-id="' . $img->id . '" alt="hero alt"><p>Text.</p>',
            'featured_media_id' => $img->id,
        ]);

        $shellBase = $this->dataPath . '/sites/lcpinject/shell/templates/base.html.twig';
        $template = (string) file_get_contents($shellBase);
        self::assertStringContainsString('page.preload', $template, 'precondition: the bundled shell emits page.preload');
        file_put_contents($shellBase, str_replace('{% if page.preload %}{{ page.preload|raw }}{% endif %}', '', $template));

        $this->build->build($site);
        $html = $this->out('lcpinject', 'hero-page/index.html');

        self::assertSame(1, $this->imagePreloadCount($html), 'the preload was injected exactly once');
        $headEnd = stripos($html, '</head>');
        self::assertNotFalse($headEnd);
        self::assertSame(1, $this->imagePreloadCount(substr($html, 0, $headEnd)), 'and it sits in the head');
    }

    /** The read paths finally expose the field, so the value is verifiable without inspecting shipped HTML. */
    public function testReadPathsReturnFeaturedMediaId(): void
    {
        $site = $this->sites->create('lcpread', 'Read Test', 'https://lcpread.example.com');
        $img = $this->media->ingest($site, $this->pngBytes(1200, 800), 'hero.png', 'hero alt');
        $post = $this->content->create($site, [
            'type' => 'post', 'title' => 'Readable', 'body_html' => '<p>t</p>', 'status' => 'published',
            'featured_media_id' => $img->id,
        ]);

        $row = $this->contentRepo->findById($site->handle, $post->id);
        self::assertNotNull($row);
        $data = \Fastsite\Api\V1\ApiHelpers::contentToArray($row);
        self::assertSame($img->id, $data['featured_media_id']);
    }
}
