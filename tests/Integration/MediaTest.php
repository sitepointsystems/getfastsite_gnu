<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\MediaRenderer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Media pipeline against real sharp (Node). Skipped when node is absent.
 */
final class MediaTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private MediaService $media;
    private DbalMediaRepository $repository;
    private Site $site;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — media tests skipped.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-media-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->databases->provisionSite('mtest');
        $migrator->migrate($this->databases->site('mtest'), $appRoot . '/migrations/site');

        $this->repository = new DbalMediaRepository($this->databases, $clock);
        $this->media = new MediaService(
            $this->repository,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            new \Fastsite\Media\NullImageOptimizer(new NullLogger()),
            $appRoot,
        );
        $this->site = new Site(1, 'mtest', 'Media Test', '', 'https://m.example.com', 'en', [], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');
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

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 6, 214, 160));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function testIngestGeneratesVariantContract(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available to synthesize a test image.');
        }

        $media = $this->media->ingest($this->site, $this->pngBytes(1600, 900), 'hero.png', 'a hero');

        self::assertSame('ready', $media->status);
        self::assertSame(1600, $media->width);
        self::assertSame(900, $media->height);

        $formats = [];
        foreach ($media->variants as $v) {
            if ($v->purpose === 'responsive') {
                $formats[$v->format] = true;
            }
        }
        // The variant contract: AVIF + WebP + an original-format fallback.
        self::assertArrayHasKey('avif', $formats);
        self::assertArrayHasKey('webp', $formats);
        self::assertArrayHasKey('png', $formats);

        // OG crop exists.
        $og = array_filter($media->variants, static fn ($v): bool => $v->purpose === 'og');
        self::assertCount(1, $og);
    }

    public function testShaDedupeReturnsSameMedia(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $bytes = $this->pngBytes(800, 600);
        $a = $this->media->ingest($this->site, $bytes, 'dup.png', 'alt');
        $b = $this->media->ingest($this->site, $bytes, 'dup2.png', 'alt');

        self::assertSame($a->id, $b->id);
    }

    public function testAltRequiredForImages(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingest($this->site, $this->pngBytes(400, 300), 'noalt.png', '');
    }

    public function testMimeMustMatchExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // .png extension but plain-text bytes.
        $this->media->ingest($this->site, 'this is not a png', 'fake.png', 'alt');
    }

    public function testBodyImgExpandsToPicture(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $media = $this->media->ingest($this->site, $this->pngBytes(1200, 800), 'inline.png', 'inline alt');

        $renderer = new MediaRenderer($this->repository);
        $result = $renderer->expandBody('mtest', '<p>x</p><img data-media-id="' . $media->id . '" alt="inline alt"><p>y</p>');

        self::assertStringContainsString('<picture>', $result['html']);
        self::assertStringContainsString('type="image/avif"', $result['html']);
        self::assertStringContainsString('loading="lazy"', $result['html']);
        self::assertMatchesRegularExpression('/width="\d+" height="\d+"/', $result['html']);
        self::assertSame([$media->id], $result['media_ids']);
    }

    public function testFeaturedImageIsEagerWithPreload(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $media = $this->media->ingest($this->site, $this->pngBytes(1600, 900), 'feat.png', 'featured alt');

        $renderer = new MediaRenderer($this->repository);
        $result = $renderer->featured('mtest', $media->id);

        self::assertStringContainsString('fetchpriority="high"', $result['html']);
        self::assertStringContainsString('loading="eager"', $result['html']);
        self::assertNotNull($result['preload']);
        self::assertStringContainsString('rel="preload"', (string) $result['preload']);
    }
}
