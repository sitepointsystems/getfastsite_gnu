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

    private function jpegBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 214, 96, 6));
        ob_start();
        imagejpeg($img, null, 85);

        return (string) ob_get_clean();
    }

    /** A poorly-compressing PNG (random pixels) — plenty of room to "optimize". */
    private function noisyPngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; ++$x) {
            for ($y = 0; $y < $h; ++$y) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** A MediaService whose GLOBAL optimizer is the given stub (shares this test's DB). */
    private function serviceWith(\Fastsite\Media\ImageOptimizer $optimizer): MediaService
    {
        return new MediaService(
            $this->repository,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            $optimizer,
            \dirname(__DIR__, 2),
        );
    }

    /** @param string $result what optimize() hands back */
    private function stubOptimizer(string $result, bool $fails = false): \Fastsite\Media\ImageOptimizer
    {
        return new class($result, $fails) implements \Fastsite\Media\ImageOptimizer {
            public int $calls = 0;

            public function __construct(private readonly string $result, private readonly bool $fails)
            {
            }

            public function optimize(string $bytes, string $filename): string
            {
                ++$this->calls;

                return $this->fails ? $bytes : $this->result;
            }

            public function lastAttemptFailed(): bool
            {
                return $this->fails;
            }

            public function lastError(): ?string
            {
                return $this->fails ? 'stub failure' : null;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function describe(): string
            {
                return 'stub';
            }
        };
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
        // The variant contract: AVIF + WebP + a fallback — jpg for anything
        // opaque (a full-width PNG re-encode of a photo is >1 MB and is what
        // naive audit tools fetch from <img src>).
        self::assertArrayHasKey('avif', $formats);
        self::assertArrayHasKey('webp', $formats);
        self::assertArrayHasKey('jpg', $formats);
        self::assertArrayNotHasKey('png', $formats);

        // OG crop exists.
        $og = array_filter($media->variants, static fn ($v): bool => $v->purpose === 'og');
        self::assertCount(1, $og);
    }

    public function testTransparentPngKeepsPngFallback(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $img = imagecreatetruecolor(600, 400);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 10, 20, 30, 100));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();

        $media = $this->media->ingest($this->site, $bytes, 'logo.png', 'a transparent logo');

        $formats = [];
        foreach ($media->variants as $v) {
            if ($v->purpose === 'responsive') {
                $formats[$v->format] = true;
            }
        }
        // Real transparency must keep the png fallback (jpg would flatten it).
        self::assertArrayHasKey('png', $formats);
        self::assertArrayNotHasKey('jpg', $formats);
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

    public function testMissingAltIsAutoGeneratedNotRejected(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        // No AI factory in this fixture → the filename-derived fallback fills
        // the alt; an image upload must never fail over missing alt.
        $media = $this->media->ingest($this->site, $this->pngBytes(400, 300), 'sunset_over-harbor.png', '');
        self::assertSame('Sunset over harbor', $media->alt);

        // Decorative images keep their empty alt.
        $decorative = $this->media->ingest($this->site, $this->pngBytes(401, 300), 'divider.png', '', true);
        self::assertSame('', $decorative->alt);
        self::assertTrue($decorative->decorative);
    }

    public function testBase64UploadLandsInTheSameLibraryAsRawBytes(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $bytes = $this->pngBytes(900, 600);
        $direct = $this->media->ingest($this->site, $bytes, 'twin.png', 'a twin');
        // Identical bytes through the base64 path hit the SAME sha256 dedupe —
        // proof the two upload paths share one library and one pipeline.
        $encoded = $this->media->ingestBase64($this->site, base64_encode($bytes), 'twin.png', 'a twin');

        self::assertSame($direct->id, $encoded->id);
        self::assertSame('ready', $encoded->status);
        self::assertNotSame([], $encoded->variants);
    }

    public function testBase64AcceptsWrappedDataUriAndSniffsTheTypeWithoutAFilename(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        // A data: URI wrapped the way transports wrap long base64, and NO
        // filename — the extension has to come from the bytes.
        $data = "data:image/png;base64,\n" . chunk_split(base64_encode($this->pngBytes(640, 480)), 76, "\n");
        $media = $this->media->ingestBase64($this->site, $data, '', 'a sniffed png');

        self::assertSame('image', $media->kind);
        self::assertSame('png', $media->ext);
        self::assertSame('ready', $media->status);
        self::assertSame(640, $media->width);
        self::assertSame(480, $media->height);
        self::assertNotSame([], $media->variants);
    }

    public function testBase64CarriesNonImageFilesToo(): void
    {
        $media = $this->media->ingestBase64($this->site, base64_encode('total,rows' . "\n" . '3,4'), 'report.csv', '');

        self::assertSame('file', $media->kind);
        self::assertSame('csv', $media->ext);
        self::assertSame('ready', $media->status);
    }

    public function testBase64RejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingestBase64($this->site, 'not base64 at all!!', 'x.png', 'alt');
    }

    public function testBase64RejectsAnEmptyPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingestBase64($this->site, "   \n  ", 'x.png', 'alt');
    }

    public function testBase64RejectsANonBase64DataUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingestBase64($this->site, 'data:image/svg+xml,%3Csvg%2F%3E', '', 'alt');
    }

    public function testBase64EnforcesTheCapBeforeDecoding(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingestBase64($this->site, base64_encode($this->pngBytes(400, 300)), 'big.png', 'alt', false, 100);
    }

    public function testBase64StillVerifiesContentAgainstTheExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // .png extension, plain-text bytes — the same guard the raw path has.
        $this->media->ingestBase64($this->site, base64_encode('this is not a png'), 'fake.png', 'alt');
    }

    /**
     * The upload-ticket surface end to end: mint, PUT the raw bytes, get the
     * media row back. This is the path that exists so bytes never have to
     * travel through a model's output, so the thing worth asserting is that a
     * plain body — no multipart, no base64, no filename — lands as a real
     * image with variants.
     */
    public function testAnUploadTicketTakesRawBytesAndReturns201(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $tickets = new \Fastsite\Media\Service\UploadTicketService($this->databases, new SystemClock(), 'https://app.example.com');
        $handler = new \Fastsite\Media\Handler\TicketUploadHandler(
            $tickets,
            new \Fastsite\Tests\Support\SingleSiteRepository($this->site),
            $this->media,
        );

        $minted = $tickets->create('mtest', 'oauth', 7, '', 'over a ticket');
        $response = $handler->handle($this->ticketRequest($minted['token'], $this->pngBytes(600, 400)));

        self::assertSame(201, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('image', $payload['data']['kind']);
        self::assertSame('over a ticket', $payload['data']['alt']);
        self::assertSame(600, $payload['data']['width']);
        // No filename was given, so the extension came off the bytes.
        self::assertSame('upload.png', $payload['data']['original_name']);
        self::assertStringContainsString('data-media-id="' . $payload['data']['id'] . '"', (string) $payload['data']['use_in_body']);
    }

    public function testASpentUploadTicketIsRefusedWithoutStoringAnything(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $tickets = new \Fastsite\Media\Service\UploadTicketService($this->databases, new SystemClock(), 'https://app.example.com');
        $handler = new \Fastsite\Media\Handler\TicketUploadHandler(
            $tickets,
            new \Fastsite\Tests\Support\SingleSiteRepository($this->site),
            $this->media,
        );

        $minted = $tickets->create('mtest', 'oauth', 7);
        $first = $handler->handle($this->ticketRequest($minted['token'], $this->pngBytes(120, 90)));
        self::assertSame(201, $first->getStatusCode());

        // Replay the SAME url. 404 rather than 401: telling an anonymous caller
        // "valid but used" would make this an oracle for guessing live tokens.
        $replay = $handler->handle($this->ticketRequest($minted['token'], $this->pngBytes(120, 90)));
        self::assertSame(404, $replay->getStatusCode());
    }

    public function testAnUploadTicketRefusesAnEmptyBodyAndAnOverCapBody(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $tickets = new \Fastsite\Media\Service\UploadTicketService($this->databases, new SystemClock(), 'https://app.example.com');
        $handler = new \Fastsite\Media\Handler\TicketUploadHandler(
            $tickets,
            new \Fastsite\Tests\Support\SingleSiteRepository($this->site),
            $this->media,
        );

        $empty = $tickets->create('mtest', 'oauth', 7);
        self::assertSame(422, $handler->handle($this->ticketRequest($empty['token'], ''))->getStatusCode());

        $tiny = $tickets->create('mtest', 'oauth', 7, '', '', false, 64);
        self::assertSame(413, $handler->handle($this->ticketRequest($tiny['token'], $this->pngBytes(300, 300)))->getStatusCode());
    }

    private function ticketRequest(string $token, string $bytes): \GuzzleHttp\Psr7\ServerRequest
    {
        return (new \GuzzleHttp\Psr7\ServerRequest('PUT', '/u/' . $token, [], $bytes))
            ->withAttribute('token', $token);
    }

    /**
     * A caller holding bytes often has no reliable filename, and the one it
     * invents is frequently the only thing wrong with the request — an agent
     * calling every image "photo.png" lost every JPEG it ever held. We sniff
     * the bytes anyway to write the error, so the extension is reconciled to
     * them instead of refusing a file we can already name.
     */
    public function testAMismatchedImageExtensionIsCorrectedToTheSniffedType(): void
    {
        if (!\function_exists('imagejpeg')) {
            self::markTestSkipped('GD not available.');
        }
        $media = $this->media->ingest($this->site, $this->jpegBytes(320, 240), 'photo.png', 'a jpeg misnamed png');

        self::assertSame('image', $media->kind);
        self::assertSame('jpg', $media->ext, 'the extension must follow the bytes, not the caller');
        self::assertSame('photo.jpg', $media->originalName);
        self::assertSame('ready', $media->status);
        self::assertNotSame([], $media->variants);
    }

    /**
     * The restriction that makes the correction above safe. `.html` files are
     * inlined VERBATIM at build, so reconciling ACROSS kinds would promote a
     * rejected upload into stored XSS. Outside the image/video maps, a
     * mismatch is still a hard refusal.
     */
    public function testAMismatchIsStillRefusedWhenTheBytesAreNotTheSameKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->media->ingest($this->site, '<html><script>alert(1)</script></html>', 'photo.png', 'alt');
    }

    /**
     * A multipart client that sends no filename, or sends one with no
     * extension, used to have its image filed as a download link.
     */
    public function testAnExtensionlessNameWithImageBytesBecomesAnImage(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $media = $this->media->ingest($this->site, $this->pngBytes(240, 160), 'hero', 'no extension given');

        self::assertSame('image', $media->kind);
        self::assertSame('png', $media->ext);
        self::assertSame('hero.png', $media->originalName);
        self::assertNotSame([], $media->variants);
    }

    /**
     * The other half, and the reason the guard is narrow: only the image and
     * video maps qualify, so a genuinely extensionless FILE keeps its name
     * instead of being renamed to something.bin.
     */
    public function testAnExtensionlessNonMediaFileKeepsItsNameAndStaysAFile(): void
    {
        $media = $this->media->ingest($this->site, "all:\n\tphp bin/fastsite build\n", 'Makefile', '');

        self::assertSame('file', $media->kind);
        self::assertSame('Makefile', $media->originalName, 'a Makefile must not become Makefile.bin');
    }

    /**
     * The REST surface: a JSON `{data}` body must reach the base64 intake and
     * come back in the same 201 envelope the multipart/url shapes use.
     */
    public function testRestJsonDataBodyUploadsAndReturns201(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $sites = new class($this->site) implements \Fastsite\Site\Repository\SiteRepository {
            public function __construct(private readonly Site $site)
            {
            }

            public function findByHandle(string $handle): ?Site
            {
                return $handle === $this->site->handle ? $this->site : null;
            }

            public function findAnyByHandle(string $handle): ?Site
            {
                return $this->findByHandle($handle);
            }

            public function findByPrimaryDomain(string $domain): ?Site
            {
                return null;
            }

            /** @return list<Site> */
            public function all(): array
            {
                return [$this->site];
            }

            /** @return list<Site> */
            public function listDeleted(): array
            {
                return [];
            }

            public function softDelete(int $id): void
            {
            }

            public function restore(int $id): void
            {
            }

            public function purge(int $id): void
            {
            }

            public function insert(array $fields): int
            {
                return 0;
            }

            public function update(int $id, array $fields): void
            {
            }
        };

        $handler = new \Fastsite\Api\V1\MediaUploadHandler(
            $sites,
            $this->media,
            new \Fastsite\Access\Service\AuditLogger($this->databases, new SystemClock(), new NullLogger()),
        );

        $body = json_encode(['data' => base64_encode($this->pngBytes(500, 400)), 'filename' => 'rest.png', 'alt' => 'over rest'], JSON_THROW_ON_ERROR);
        $request = (new \GuzzleHttp\Psr7\ServerRequest('POST', '/api/v1/sites/mtest/media', ['Content-Type' => 'application/json'], $body))
            ->withAttribute('site', 'mtest')
            ->withAttribute(\Fastsite\Access\AccessContext::REQUEST_ATTRIBUTE, new \Fastsite\Access\AccessContext('api_key', 1, 1, ['admin'], ['*']));

        $response = $handler->handle($request);
        self::assertSame(201, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertSame('image', $payload['data']['kind']);
        self::assertSame('over rest', $payload['data']['alt']);
        self::assertSame(500, $payload['data']['width']);
        self::assertGreaterThan(0, $payload['data']['variant_count']);
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

    /**
     * A featured image authored IN THE BODY is the LCP wherever the shell
     * renders (or doesn't render) featured_html — its first body occurrence
     * must be eager+high and supply the preload, later occurrences stay lazy,
     * and an explicit featured id beats the hero-size heuristic even when a
     * larger image comes first.
     */
    public function testBodyOccurrenceOfFeaturedImageIsPrioritized(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $other = $this->media->ingest($this->site, $this->pngBytes(1600, 1000), 'other.png', 'other alt');
        $hero = $this->media->ingest($this->site, $this->pngBytes(1200, 700), 'hero.png', 'hero alt');

        $renderer = new MediaRenderer($this->repository);
        $html = '<img data-media-id="' . $other->id . '" alt="other alt">'
            . '<img data-media-id="' . $hero->id . '" alt="hero alt">'
            . '<img data-media-id="' . $hero->id . '" alt="hero alt">';
        $result = $renderer->expandBody('mtest', $html, $hero->id, true);

        self::assertSame(1, substr_count($result['html'], 'fetchpriority="high"'));
        self::assertMatchesRegularExpression(
            '#<img src="/media/' . $hero->id . '/[^>]*loading="eager" fetchpriority="high"#',
            $result['html'],
        );
        self::assertNotNull($result['preload']);
        // The larger non-featured image stays lazy — explicit choice wins.
        self::assertMatchesRegularExpression(
            '#<img src="/media/' . $other->id . '/[^>]*loading="lazy"#',
            $result['html'],
        );
    }

    /** With no featured id, the first hero-sized (≥1000px) image is auto-prioritized; small ones are passed over. */
    public function testAutoPriorityPicksFirstHeroSizedImageAndSkipsSmallOnes(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $small = $this->media->ingest($this->site, $this->pngBytes(320, 240), 'small.png', 'small alt');
        $big = $this->media->ingest($this->site, $this->pngBytes(1100, 700), 'big.png', 'big alt');

        $renderer = new MediaRenderer($this->repository);
        $result = $renderer->expandBody(
            'mtest',
            '<img data-media-id="' . $small->id . '" alt="small alt"><img data-media-id="' . $big->id . '" alt="big alt">',
            null,
            true,
        );

        self::assertSame(1, substr_count($result['html'], 'fetchpriority="high"'));
        self::assertNotNull($result['preload']);

        $onlySmall = $renderer->expandBody('mtest', '<img data-media-id="' . $small->id . '" alt="small alt">', null, true);
        self::assertStringNotContainsString('fetchpriority', $onlySmall['html']);
        self::assertNull($onlySmall['preload']);
    }

    /** Fragment expansion (the default args) never runs the priority pass — an intro image is not the LCP. */
    public function testDefaultExpansionNeverPrioritizes(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $big = $this->media->ingest($this->site, $this->pngBytes(1500, 900), 'frag.png', 'frag alt');

        $renderer = new MediaRenderer($this->repository);
        $result = $renderer->expandBody('mtest', '<img data-media-id="' . $big->id . '" alt="frag alt">');

        self::assertStringNotContainsString('fetchpriority', $result['html']);
        self::assertNull($result['preload']);
    }

    public function testOptimizeExistingSwapsOriginalAndMarksOnce(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        // Ingested through the Null optimizer = never optimized (pre-key upload).
        $noisy = $this->noisyPngBytes(300, 200);
        $media = $this->media->ingest($this->site, $noisy, 'retro.png', 'alt');
        self::assertNull($media->optimizedAt);
        self::assertSame([$media->id], $this->repository->unoptimizedImageIds('mtest'));

        $small = $this->pngBytes(300, 200);
        self::assertLessThan(\strlen($noisy), \strlen($small));
        $stub = $this->stubOptimizer($small);
        $result = $this->serviceWith($stub)->optimizeExisting($this->site, $media->id);

        self::assertTrue($result['changed']);
        self::assertSame(\strlen($noisy) - \strlen($small), $result['saved']);
        $after = $this->repository->findById('mtest', $media->id);
        self::assertNotNull($after);
        self::assertNotNull($after->optimizedAt);
        self::assertSame(\strlen($small), $after->bytes);
        self::assertNotSame([], $after->variants); // regenerated from the smaller source
        self::assertSame([], $this->repository->unoptimizedImageIds('mtest'));

        // A second run is a no-op — credits are never spent twice.
        $again = $this->serviceWith($stub)->optimizeExisting($this->site, $media->id);
        self::assertFalse($again['changed']);
        self::assertSame(1, $stub->calls);
    }

    public function testFailedOptimizeAttemptStaysRetryable(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $media = $this->media->ingest($this->site, $this->pngBytes(300, 200), 'flaky.png', 'alt');

        try {
            $this->serviceWith($this->stubOptimizer('', fails: true))->optimizeExisting($this->site, $media->id);
            self::fail('A failed optimizer attempt must throw.');
        } catch (\RuntimeException) {
            // expected
        }

        // Unmarked = still in the work list for a retry.
        $after = $this->repository->findById('mtest', $media->id);
        self::assertNotNull($after);
        self::assertNull($after->optimizedAt);
        self::assertSame([$media->id], $this->repository->unoptimizedImageIds('mtest'));
    }

    public function testIngestMarksOptimizedWhenOptimizerConfigured(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }
        $bytes = $this->pngBytes(300, 200);
        // A configured optimizer that finds no gain still counts as done.
        $media = $this->serviceWith($this->stubOptimizer($bytes))->ingest($this->site, $bytes, 'fresh.png', 'alt');

        self::assertNotNull($media->optimizedAt);
        self::assertSame([], $this->repository->unoptimizedImageIds('mtest'));
    }
}
