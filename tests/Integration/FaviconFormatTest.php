<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The favicon must be a format Google Search can actually read.
 *
 * Google's own text: "Google Search supports the following favicon file
 * formats: BMP, GIF, ICO, PNG, JPEG, PPM, and TIFF" — avif and webp are not
 * on that list. The build used to take the SMALLEST responsive variant, and
 * the worker emits avif+webp at every width with a jpg/png fallback only at
 * the largest, so the answer was always an avif.
 *
 * This is the worst shape of bug: the icon renders perfectly in the browser
 * tab, so the only symptom is a blank square in the search result months
 * later with nothing pointing at the cause. Hence a test that reads the
 * emitted HTML rather than the intent.
 */
final class FaviconFormatTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private ContentService $content;
    private MediaService $media;
    private BuildService $build;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — needs real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-favicon-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);

        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->media = new MediaService($mediaRepo, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);

        $records = &$this->logRecords;
        $logger = new class($records) extends \Psr\Log\AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);
        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            $logger,
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

    /**
     * A transparent PNG favicon ships as PNG — never the avif the smallest
     * responsive variant always is.
     */
    public function testTheEmittedFaviconIsPngNotAvif(): void
    {
        $href = $this->buildWithFavicon('fav', $this->squarePng(256, true));

        self::assertStringEndsWith('.png', $href, 'Google supports PNG; avif and webp are not on its list');
        self::assertStringContainsString('type="image/png"', $this->home('fav'));
        self::assertFileExists($this->dataPath . '/sites/fav/build' . $href, 'and the file has to actually ship');
    }

    /**
     * An opaque source has no PNG fallback — the pipeline makes a jpg, which
     * is equally on Google's list. What must never happen is falling back to
     * avif because png was absent.
     */
    public function testAnOpaqueFaviconShipsAsJpegRatherThanAvif(): void
    {
        $href = $this->buildWithFavicon('fav2', $this->squarePng(256, false));

        self::assertStringEndsWith('.jpg', $href);
        self::assertStringContainsString('type="image/jpeg"', $this->home('fav2'));
        self::assertFileExists($this->dataPath . '/sites/fav2/build' . $href);
    }

    /** No favicon at all is a warning, not silence — the SVG fallback is invisible to Google. */
    public function testNoFaviconWarnsThatSearchWillShowNothing(): void
    {
        $site = $this->sites->create('fav3', 'Fav', 'https://fav3.example.com');
        $this->content->create($site, ['type' => 'post', 'title' => 'Hi', 'slug' => 'hi', 'status' => 'published', 'body_html' => '<p>Hi.</p>']);
        $this->build->build($site);

        self::assertStringContainsString('No favicon is set', $this->warnings());
    }

    /** Google requires 1:1, and a proportional resize of a wide source is not. */
    public function testANonSquareFaviconIsCalledOut(): void
    {
        $this->buildWithFavicon('fav4', $this->squarePng(320, true, 120));

        self::assertStringContainsString('Google requires a square', $this->warnings());
    }

    private function buildWithFavicon(string $handle, string $bytes): string
    {
        $site = $this->sites->create($handle, 'Fav', 'https://' . $handle . '.example.com');
        $icon = $this->media->ingest($site, $bytes, 'favicon.png', 'the site icon');
        $this->sites->update($handle, ['favicon_media_id' => $icon->id]);
        $site = $this->siteRepo->findByHandle($handle);
        self::assertNotNull($site);

        $this->content->create($site, ['type' => 'post', 'title' => 'Hi', 'slug' => 'hi', 'status' => 'published', 'body_html' => '<p>Hi.</p>']);
        $this->build->build($site);

        self::assertSame(
            1,
            preg_match('~<link rel="icon"[^>]*href="([^"]+)"~', $this->home($handle), $m),
            'the shell must emit a favicon link',
        );

        return $m[1];
    }

    private function home(string $handle): string
    {
        $file = $this->dataPath . '/sites/' . $handle . '/build/index.html';
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private function warnings(): string
    {
        $out = '';
        foreach ($this->logRecords as $r) {
            $out .= (string) ($r['context']['message'] ?? '') . "\n";
        }

        return $out;
    }

    /**
     * `$alpha` decides the fallback format the pipeline picks: png is kept
     * only for real transparency, everything opaque becomes jpg.
     */
    private function squarePng(int $w, bool $alpha, ?int $h = null): string
    {
        $img = imagecreatetruecolor($w, $h ?? $w);
        if ($alpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        } else {
            imagefill($img, 0, 0, imagecolorallocate($img, 12, 20, 30));
        }
        imagefilledellipse($img, (int) ($w / 2), (int) (($h ?? $w) / 2), (int) ($w * 0.6), (int) (($h ?? $w) * 0.6), imagecolorallocate($img, 42, 134, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
