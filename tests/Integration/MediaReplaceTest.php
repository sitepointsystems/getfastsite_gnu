<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\FileRenderer;
use Fastsite\Media\NullImageOptimizer;
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
 * Replacing the CONTENT behind a media id.
 *
 * The id, the name and the alt survive so every `<img data-media-id>` and
 * `{{ filename }}` embed keeps working — but the EMITTED URL must change,
 * because /media/* and /files/* are served `immutable` for a year and a
 * reused filename would leave every browser and the CDN showing the old file.
 * A failed replace must leave the previous image intact and still serving.
 */
final class MediaReplaceTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private DbalMediaRepository $repository;
    private MediaService $media;
    private Site $site;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — media replace tests skipped.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-replace-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');
        $this->databases->provisionSite('rtest');
        $migrator->migrate($this->databases->site('rtest'), $appRoot . '/migrations/site');

        $this->repository = new DbalMediaRepository($this->databases, $clock);
        $this->media = new MediaService(
            $this->repository,
            $this->databases,
            new SafeUrlFetcher(),
            new NullLogger(),
            new NullImageOptimizer(new NullLogger()),
            $appRoot,
        );
        $this->site = new Site(1, 'rtest', 'Replace Test', '', 'https://r.example.com', 'en', [], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');
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

    private function pngBytes(int $w, int $h, int $r = 6, int $g = 214, int $b = 160): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function testReplaceKeepsTheIdAndNameButMovesEveryUrl(): void
    {
        $original = $this->media->ingest($this->site, $this->pngBytes(1200, 600), 'logo.png', 'The logo');
        $beforePaths = array_map(static fn ($v): string => $v->diskPath, $original->variants);
        self::assertNotSame([], $beforePaths);
        self::assertSame(0, $original->revision);

        $replaced = $this->media->replace($this->site, $original->id, $this->pngBytes(800, 400, 255, 0, 0), 'logo.png');

        // The identity every embed depends on is untouched.
        self::assertSame($original->id, $replaced->id);
        self::assertSame('logo.png', $replaced->originalName);
        self::assertSame('The logo', $replaced->alt, 'An existing alt is the author\'s and stands.');
        self::assertSame(1, $replaced->revision);
        self::assertSame(800, $replaced->width, 'Dimensions come from the new file.');

        // …and every emitted URL moved, or the immutable cache would win.
        $afterPaths = array_map(static fn ($v): string => $v->diskPath, $replaced->variants);
        self::assertNotSame([], $afterPaths);
        self::assertSame([], array_intersect($beforePaths, $afterPaths), 'No variant may reuse a previous revision\'s filename.');
        foreach ($afterPaths as $path) {
            self::assertStringContainsString('logo-r1-', $path);
        }

        // The superseded files are gone from disk, not merely unreferenced.
        $sitePath = $this->databases->sitePath('rtest');
        foreach ($beforePaths as $path) {
            self::assertFileDoesNotExist($sitePath . '/' . $path);
        }
        self::assertFileExists($sitePath . '/' . $replaced->diskPath);
    }

    public function testFilePublicPathCarriesTheRevisionSoEmbedsResolveToNewBytes(): void
    {
        $file = $this->media->ingest($this->site, "v1 content\n", 'rates.csv', '');
        self::assertSame('rates', $file->publicSlug());

        // Dropped from disk under a DIFFERENT local name — the item keeps its
        // own, or `{{ rates.csv }}` would stop resolving and the build would
        // drop the link from every page that carried it.
        $replaced = $this->media->replace($this->site, $file->id, "v2 content, different\n", 'rates-2026-final.csv');
        self::assertSame('rates.csv', $replaced->originalName, 'A replace never renames the item.');
        self::assertSame('rates-r1', $replaced->publicSlug());

        // The {{ filename }} lookup still resolves on the STORED slug, so the
        // embed in already-published content keeps matching.
        $found = $this->repository->findFileByRef('rtest', 'rates.csv');
        self::assertNotNull($found);
        self::assertSame($file->id, $found->id);
        self::assertSame('rates-r1', $found->publicSlug());

        $renderer = new FileRenderer($this->repository, $this->databases, new NullLogger(), \dirname(__DIR__, 2));
        self::assertSame('/files/' . $file->id . '/rates-r1.csv', $renderer->publicPath($found->id, $found->publicSlug(), $found->ext));
    }

    public function testAFailedReplaceLeavesThePreviousImageServing(): void
    {
        $original = $this->media->ingest($this->site, $this->pngBytes(600, 400), 'hero.png', 'Hero');
        $sitePath = $this->databases->sitePath('rtest');

        // Bytes that are not a PNG at all — rejected before anything is swapped.
        try {
            $this->media->replace($this->site, $original->id, 'definitely not an image', 'hero.png');
            self::fail('A non-image replacement must be rejected.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $after = $this->repository->findById('rtest', $original->id);
        self::assertNotNull($after);
        self::assertSame(0, $after->revision, 'A rejected replace burns no revision.');
        self::assertSame($original->sha256, $after->sha256);
        self::assertFileExists($sitePath . '/' . $after->diskPath, 'The working original is untouched.');
        foreach ($after->variants as $variant) {
            self::assertFileExists($sitePath . '/' . $variant->diskPath, 'The live variants still exist.');
        }
    }

    public function testKindChangeAndDuplicateBytesAreRefusedWithAReason(): void
    {
        $image = $this->media->ingest($this->site, $this->pngBytes(300, 300), 'photo.png', 'Photo');
        $other = $this->media->ingest($this->site, $this->pngBytes(320, 320, 1, 2, 3), 'other.png', 'Other');

        // An <img> embed cannot survive becoming a PDF.
        try {
            $this->media->replace($this->site, $image->id, "%PDF-1.4\n%fake\n", 'notes.pdf');
            self::fail('A kind change must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('would break every page', $e->getMessage());
        }

        // Everything unknown is kind='file', so the kind check alone would let
        // a .html widget become a .zip download on every page.
        $widget = $this->media->ingest($this->site, "<b>calc</b>\n", 'calc.html', '');
        try {
            $this->media->replace($this->site, $widget->id, "PK\x03\x04zip-ish\n", 'calc.zip');
            self::fail('A file\'s extension must be pinned — it decides how the build renders the embed.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('how #' . $widget->id . ' is rendered', $e->getMessage());
        }

        // sha256 is UNIQUE per site — the collision is named, not a 500.
        try {
            $this->media->replace($this->site, $image->id, $this->pngBytes(320, 320, 1, 2, 3), 'photo.png');
            self::fail('Replacing with another row\'s exact bytes must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('#' . $other->id, $e->getMessage());
        }

        // Replacing something with itself would invalidate a year of cached
        // URLs for no change at all.
        try {
            $this->media->replace($this->site, $image->id, $this->pngBytes(300, 300), 'photo.png');
            self::fail('An identical replace must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nothing to replace', $e->getMessage());
        }

        self::assertSame(0, $this->repository->findById('rtest', $image->id)?->revision);
    }

    public function testEmptyAltIsGeneratedOnReplaceButAnExistingOneIsKept(): void
    {
        // Decorative stays decorative and unlabelled.
        $decorative = $this->media->ingest($this->site, $this->pngBytes(100, 100), 'spacer.png', '', true);
        $this->media->replace($this->site, $decorative->id, $this->pngBytes(120, 120, 9, 9, 9), 'spacer.png');
        self::assertSame('', $this->repository->findById('rtest', $decorative->id)?->alt);

        // An empty alt is filled exactly as an upload would fill it.
        $bare = $this->media->ingest($this->site, $this->pngBytes(200, 150), 'sunset-over-water.png', '');
        $this->repository->update('rtest', $bare->id, ['alt' => '']);
        $replaced = $this->media->replace($this->site, $bare->id, $this->pngBytes(210, 160, 3, 3, 3), 'sunset-over-water.png');
        self::assertNotSame('', $replaced->alt);
    }
}
