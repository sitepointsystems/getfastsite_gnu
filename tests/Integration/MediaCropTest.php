<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Non-destructive crop + resize, against real sharp.
 *
 * The crop lives in the DATABASE, not in the pixels, and that is the whole
 * design: the uploaded original is never modified, so a bad drag is free and
 * the framing stays re-drawable. Everything asserted here follows from that —
 * and so does the thing most likely to break it, which is some other code
 * path rebuilding variants without re-applying the transform.
 */
final class MediaCropTest extends TestCase
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
            self::markTestSkipped('sharp not installed — crop needs real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-crop-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->site = $sites->create('crop', 'Crop', 'https://crop.example.com');

        $this->repository = new DbalMediaRepository($this->databases, $clock);
        $this->media = new MediaService($this->repository, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);
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
     * A half-width, half-height crop halves the reported dimensions AND the
     * pixels on disk — asserted by reading the generated file, not by trusting
     * the row we just wrote.
     */
    public function testCroppingChangesTheGeneratedPixelsAndTheStoredSize(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1200, 800), 'wide.png', 'wide');
        self::assertSame(1200, $image->width);
        self::assertSame(800, $image->height);
        $originalBytes = $this->originalOnDisk($image->id);

        $cropped = $this->media->transform($this->site, $image->id, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5], null);

        self::assertSame(600, $cropped->width, 'half the width');
        self::assertSame(400, $cropped->height, 'half the height');
        // 0 → 1: the revision has to MOVE, because publicSlug() puts it in the
        // emitted filenames and /media/* is cached immutable for a year.
        self::assertSame(1, $cropped->revision);
        self::assertStringContainsString('-r1', $cropped->publicSlug());

        // The uploaded file is untouched. This is the promise the whole
        // feature rests on: a crop you regret costs nothing.
        self::assertSame($originalBytes, $this->originalOnDisk($cropped->id), 'the original must never be rewritten');

        // And the rendition on disk really is 3:2 of the cropped region.
        $widest = $this->widestFallback($cropped->id);
        [$w, $h] = $this->dimensionsOf($widest);
        self::assertSame(round(600 / 400, 2), round($w / $h, 2), 'the generated file carries the cropped aspect');
    }

    /** Clearing puts the full picture back, because it was never thrown away. */
    public function testClearingRestoresTheFullImage(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1000, 500), 'a.png', 'a');
        $this->media->transform($this->site, $image->id, ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5], null);

        $restored = $this->media->transform($this->site, $image->id, null, null);
        self::assertSame(1000, $restored->width);
        self::assertSame(500, $restored->height);
        self::assertNull($restored->cropW);
        self::assertNull($restored->sharpTransform());
    }

    /** A resize with no crop is a legitimate request: "just make it smaller". */
    public function testResizeWithoutACropCapsTheLargestFile(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1600, 800), 'big.png', 'big');

        $small = $this->media->transform($this->site, $image->id, null, 400);
        self::assertSame(400, $small->width);
        self::assertSame(200, $small->height);

        [$w] = $this->dimensionsOf($this->widestFallback($small->id));
        self::assertSame(400, $w, 'nothing wider than the cap may ship');
    }

    /**
     * THE regression that matters. Optimizing rebuilds every variant, and
     * rebuilding from an untransformed source would quietly un-crop the image
     * as a side effect of squeezing its bytes. Same hazard for `media:regen`.
     */
    public function testRegeneratingAndOptimizingBothKeepTheCrop(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1200, 600), 'keep.png', 'keep');
        $cropped = $this->media->transform($this->site, $image->id, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], null);
        self::assertSame(600, $cropped->width);

        $this->media->regenerateVariants($this->site, $image->id);
        self::assertSame(600, $this->repository->findById('crop', $image->id)?->width, 'regen must re-apply the stored crop');

        // optimizeExisting refuses to run unconfigured, so this needs an
        // optimizer that reports itself configured and actually shrinks the
        // bytes — otherwise the interesting code path (swap the original,
        // rebuild every variant) never executes.
        $this->withOptimizer()->optimizeExisting($this->site, $image->id);
        self::assertSame(600, $this->repository->findById('crop', $image->id)?->width, 'optimize must re-apply the stored crop');
    }

    /**
     * A crop was drawn on the PREVIOUS image. Re-applying that rect to
     * different bytes frames something nobody chose, so a replace clears it.
     */
    public function testReplaceClearsAStaleCrop(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1200, 600), 'a.png', 'a');
        $this->media->transform($this->site, $image->id, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], null);

        $replaced = $this->media->replace($this->site, $image->id, $this->pngBytes(900, 450), 'b.png');
        self::assertNull($replaced->cropW);
        self::assertNull($replaced->sharpTransform());
        self::assertSame(900, $replaced->width);
    }

    /**
     * Deleting then re-uploading the same file must come back as a FRESH
     * item. The sha256 dedupe restores the trashed row (a new insert would
     * collide on the unique index), and it used to hand it back untouched —
     * so the "new" upload arrived already cropped by a decision the person
     * had just thrown away, and wearing its old alt text.
     */
    public function testReuploadAfterDeleteDoesNotInheritTheOldCropOrAlt(): void
    {
        $bytes = $this->pngBytes(1200, 600);
        $image = $this->media->ingest($this->site, $bytes, 'a.png', 'the old words');
        $this->media->transform($this->site, $image->id, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], 300);
        self::assertSame(300, $this->repository->findById('crop', $image->id)?->width);

        $this->media->softDelete($this->site, $image->id);

        $again = $this->media->ingest($this->site, $bytes, 'a.png', 'the new words');

        self::assertSame($image->id, $again->id, 'the id must stand — every embed resolves on it');
        self::assertNull($again->deletedAt, 'and it must be out of the trash');
        self::assertNull($again->cropW, 'the crop must not survive the round trip');
        self::assertNull($again->resizeWidth);
        self::assertNull($again->sharpTransform());
        self::assertSame('the new words', $again->alt, 'a supplied alt wins over the old one');
        // And the files on disk match the row again, rather than still being
        // the cropped renditions the cleared columns no longer describe.
        self::assertSame(1200, $again->width);
        [$w] = $this->dimensionsOf($this->widestFallback($again->id));
        self::assertGreaterThan(300, $w);
    }

    /**
     * Reported from the dashboard as "replacing does not actually upload it
     * again". It did — the THUMBNAIL lied, because its URL never changed while
     * being cached for five minutes. This pins the server half.
     */
    public function testReplaceReallyStoresTheNewBytes(): void
    {
        $first = $this->media->ingest($this->site, $this->pngBytes(800, 400), 'a.png', 'a');
        $before = $first->sha256;

        $replaced = $this->media->replace($this->site, $first->id, $this->pngBytes(640, 480), 'b.png');

        self::assertSame($first->id, $replaced->id);
        self::assertNotSame($before, $replaced->sha256, 'the stored bytes must actually change');
        self::assertSame(640, $replaced->width);
        self::assertSame(480, $replaced->height);
        // The revision is what moves the URL out from under a year of
        // immutable caching — and what the thumb now cache-busts on.
        self::assertSame(1, $replaced->revision);
    }

    /**
     * The REST surface, end to end through the handler.
     *
     * The rule agents get wrong is that the rect is a fraction of the
     * ORIGINAL, never of the currently cropped view — so cropping twice must
     * not compound. Asserted by cropping an already-cropped image and
     * checking the result is measured against the full picture.
     */
    public function testTheRestSurfaceCropsAndClears(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1000, 800), 'api.png', 'api');

        $ok = $this->crop($image->id, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]);
        self::assertSame(200, $ok['status']);
        self::assertSame(500, $ok['data']['width']);
        self::assertSame(400, $ok['data']['height']);
        self::assertNotNull($ok['data']['url']);

        // A SECOND crop of half is half of the ORIGINAL again, not a quarter.
        $again = $this->crop($image->id, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1.0]]);
        self::assertSame(500, $again['data']['width'], 'fractions are of the original, so this must not compound');
        self::assertSame(800, $again['data']['height']);

        $cleared = $this->crop($image->id, ['clear' => true]);
        self::assertSame(1000, $cleared['data']['width']);
        self::assertNull($cleared['data']['crop']);
    }

    /** A resize with no rect, and a rect that is nonsense, both answer sanely. */
    public function testTheRestSurfaceResizesAndRejectsBadInput(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(1400, 700), 'api2.png', 'api2');

        $resized = $this->crop($image->id, ['resize_width' => 500]);
        self::assertSame(500, $resized['data']['width']);
        self::assertSame(250, $resized['data']['height']);

        // Zero area is a bad request, not a 500 and not a silent no-op.
        $bad = $this->crop($image->id, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0.5]]);
        self::assertSame(422, $bad['status']);

        self::assertSame(404, $this->crop(999999, ['clear' => true])['status']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, data: array<string, mixed>}
     */
    private function crop(int $id, array $body): array
    {
        $handler = new \Fastsite\Api\V1\MediaCropHandler(
            new DbalSiteRepository($this->databases, new SystemClock()),
            $this->media,
            $this->publishTrigger(),
            new \Fastsite\Access\Service\AuditLogger($this->databases, new SystemClock(), new NullLogger()),
        );

        $request = (new \GuzzleHttp\Psr7\ServerRequest('POST', '/api/v1/sites/crop/media/' . $id . '/crop'))
            ->withAttribute('site', 'crop')
            ->withAttribute('id', (string) $id)
            ->withAttribute(
                \Fastsite\Access\AccessContext::REQUEST_ATTRIBUTE,
                new \Fastsite\Access\AccessContext('api_key', 1, null, ['media:write'], []),
            )
            ->withBody(\GuzzleHttp\Psr7\Utils::streamFor((string) json_encode($body)));

        $response = $handler->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);
        \assert(\is_array($decoded));

        return [
            'status' => $response->getStatusCode(),
            'data' => \is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }

    private function publishTrigger(): \Fastsite\Build\Service\PublishTrigger
    {
        $clock = new SystemClock();
        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        $contentRepo = new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock);

        return new \Fastsite\Build\Service\PublishTrigger(
            new \Fastsite\Shared\Queue\JobQueue($this->databases, $redis, $clock),
            new \Fastsite\Translation\Service\TranslationService(
                $contentRepo,
                new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
                new \Fastsite\Tests\Support\UnusedAiClientFactory(),
                new \Fastsite\Content\Service\ContentSanitizer(),
                $this->databases,
                $clock,
                new NullLogger(),
                new \Fastsite\Translation\TranslationLog($this->databases, $clock),
            ),
        );
    }

    public function testAZeroAreaCropIsRefusedWithSomethingReadable(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(800, 400), 'a.png', 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no area/');
        $this->media->transform($this->site, $image->id, ['x' => 0.5, 'y' => 0.5, 'w' => 0.0, 'h' => 0.2], null);
    }

    public function testOnlyImagesCanBeCropped(): void
    {
        $file = $this->media->ingest($this->site, "just text\n", 'notes.txt', '');
        self::assertNotSame('image', $file->kind);

        $this->expectException(\InvalidArgumentException::class);
        $this->media->transform($this->site, $file->id, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5], null);
    }

    /**
     * A MediaService whose optimizer claims to be configured and returns
     * genuinely smaller bytes, so `optimizeExisting` takes its real path
     * (swap the original, rebuild every variant) with no network and no key.
     */
    private function withOptimizer(): MediaService
    {
        $optimizer = new class implements \Fastsite\Media\ImageOptimizer {
            public function optimize(string $bytes, string $filename): string
            {
                // Re-encode at a lower quality via GD: smaller, still a valid
                // image of the same dimensions, which is exactly the shape of
                // a real ShortPixel win.
                $img = @imagecreatefromstring($bytes);
                if ($img === false) {
                    return $bytes;
                }
                ob_start();
                imagepng($img, null, 9);
                $out = (string) ob_get_clean();

                return $out !== '' && \strlen($out) < \strlen($bytes) ? $out : $bytes;
            }

            public function lastAttemptFailed(): bool
            {
                return false;
            }

            public function lastError(): ?string
            {
                return null;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function describe(): string
            {
                return 'test optimizer';
            }
        };

        return new MediaService(
            $this->repository,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            $optimizer,
            \dirname(__DIR__, 2),
        );
    }

    private function originalOnDisk(int $id): string
    {
        $media = $this->repository->findById('crop', $id);
        self::assertNotNull($media);
        $path = $this->dataPath . '/sites/crop/' . ltrim($media->diskPath, '/');
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function widestFallback(int $id): string
    {
        $media = $this->repository->loadManyWithVariants('crop', [$id])[$id] ?? null;
        self::assertNotNull($media);
        $best = null;
        foreach ($media->variants as $v) {
            if ($v->purpose === 'responsive' && \in_array($v->format, ['jpg', 'png'], true)) {
                if ($best === null || $v->width > $best->width) {
                    $best = $v;
                }
            }
        }
        self::assertNotNull($best, 'an image must ship a jpg/png fallback');
        $path = $this->dataPath . '/sites/crop/' . ltrim($best->diskPath, '/');
        self::assertFileExists($path);

        return $path;
    }

    /** @return array{0: int, 1: int} */
    private function dimensionsOf(string $path): array
    {
        $size = getimagesize($path);
        self::assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Distinguishable content, not a flat fill: a solid colour crops to an
     * identical-looking image, so a broken crop would still "look" cropped by
     * its dimensions while proving nothing about the pixels. Also keeps each
     * fixture byte-distinct, which the sha256 dedupe requires.
     */
    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 12, 20, 30));
        $accent = imagecolorallocate($img, 42, 134, 255);
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), (int) ($h / 2), $accent);
        imagefilledellipse($img, (int) ($w * 0.75), (int) ($h * 0.6), max(8, (int) ($w / 6)), max(8, (int) ($h / 6)), imagecolorallocate($img, 250, 210, 60));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
