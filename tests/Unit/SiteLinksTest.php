<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Media\Dto\Media;
use Fastsite\Media\Dto\MediaVariant;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;

/**
 * Where things live on the published site.
 *
 * The read surfaces used to hand back a slug and a media id and nothing else,
 * so every caller rebuilt the URL by hand — "base_url + /blog/<slug>/", which
 * is an assumption, not a fact, and a media filename which cannot be derived
 * from an id at all. These are the rules that make the returned link true.
 */
final class SiteLinksTest extends TestCase
{
    private function site(
        string $postPattern = '/blog/{slug}/',
        string $pagePattern = '/{slug}/',
        string $baseUrl = 'https://example.com',
        string $canonicalHost = 'apex',
    ): Site {
        return new Site(
            1,
            'demo',
            'Demo',
            '',
            $baseUrl,
            'en',
            ['da'],
            $postPattern,
            $pagePattern,
            '',
            'main',
            '',
            1,
            'active',
            canonicalHost: $canonicalHost,
        );
    }

    public function testPostAndPagePathsComeFromTheSitesOwnPatterns(): void
    {
        $site = $this->site();
        self::assertSame('/blog/hello/', SiteLinks::contentPath($site, 'post', 'hello'));
        self::assertSame('/about/', SiteLinks::contentPath($site, 'page', 'about'));

        // The reason this helper exists: /blog/ is a DEFAULT, not the contract.
        $custom = $this->site(postPattern: '/artikler/{slug}/');
        self::assertSame('/artikler/hello/', SiteLinks::contentPath($custom, 'post', 'hello'));
    }

    /** A published page whose slug is `home` IS the root — never /home/. */
    public function testHomePageResolvesToTheRoot(): void
    {
        $site = $this->site();
        self::assertSame('/', SiteLinks::contentPath($site, 'page', 'home'));
        self::assertSame('/da/', SiteLinks::contentPath($site, 'page', 'home', 'da'));

        // Only a PAGE claims the root; a post that happens to be slugged
        // "home" is still a post.
        self::assertSame('/blog/home/', SiteLinks::contentPath($site, 'post', 'home'));
    }

    public function testSecondaryLocalesArePrefixedAndThePrimaryIsNot(): void
    {
        $site = $this->site();
        self::assertSame('/blog/hello/', SiteLinks::contentPath($site, 'post', 'hello', 'en'));
        self::assertSame('/da/blog/hej/', SiteLinks::contentPath($site, 'post', 'hej', 'da'));
    }

    /**
     * Absolute URLs ride the CANONICAL origin — the same one the canonical
     * tag and the host-level 301 use. A www site must not hand out apex links.
     */
    public function testAbsoluteUsesTheCanonicalOrigin(): void
    {
        self::assertSame(
            'https://example.com/blog/hello/',
            SiteLinks::absolute($this->site(), '/blog/hello/'),
        );
        self::assertSame(
            'https://www.example.com/blog/hello/',
            SiteLinks::absolute($this->site(canonicalHost: 'www'), '/blog/hello/'),
        );

        // A site with no base_url yet has no URL, and says so by staying
        // relative rather than inventing an origin.
        self::assertSame('/blog/hello/', SiteLinks::absolute($this->site(baseUrl: ''), '/blog/hello/'));
    }

    /**
     * The link an <img src> resolves to: the widest responsive jpg/png.
     * NOT the avif/webp (those live in the srcset) and above all NOT the -og
     * crop, which is not copied into dist — handing one out is a link to a 404.
     */
    public function testMediaUrlPicksTheWidestShippedFallbackNeverTheOgCrop(): void
    {
        $media = $this->image([
            new MediaVariant('responsive', 'jpg', 768, 432, 'sites/demo/media/variants/7/hero-768.jpg', 100),
            new MediaVariant('responsive', 'jpg', 1440, 810, 'sites/demo/media/variants/7/hero-1440.jpg', 200),
            new MediaVariant('responsive', 'avif', 1440, 810, 'sites/demo/media/variants/7/hero-1440.avif', 50),
            new MediaVariant('og', 'jpg', 1200, 630, 'sites/demo/media/variants/7/hero-og.jpg', 90),
        ]);

        self::assertSame('/media/7/hero-1440.jpg', SiteLinks::mediaPath($media));
        self::assertSame('https://example.com/media/7/hero-1440.jpg', SiteLinks::mediaUrl($this->site(), $media));
    }

    /** Windows disk paths are stored with backslashes; the dist layout is not. */
    public function testWindowsDiskPathsStillProduceUrlPaths(): void
    {
        $media = $this->image([
            new MediaVariant('responsive', 'jpg', 1024, 576, 'C:\\data\\sites\\demo\\media\\variants\\7\\hero-1024.jpg', 100),
        ]);

        self::assertSame('/media/7/hero-1024.jpg', SiteLinks::mediaPath($media));
    }

    /**
     * Every rendition, widest first — the answer to "give me the avif"
     * without scraping a rendered page. og crops stay out for the same
     * reason as above.
     */
    public function testVariantListIsWidestFirstAndExcludesOgCrops(): void
    {
        $media = $this->image([
            new MediaVariant('responsive', 'webp', 768, 432, 'x/7/hero-768.webp', 40),
            new MediaVariant('responsive', 'jpg', 1440, 810, 'x/7/hero-1440.jpg', 200),
            new MediaVariant('og', 'jpg', 1200, 630, 'x/7/hero-og.jpg', 90),
        ]);

        $variants = SiteLinks::mediaVariants($this->site(), $media);
        self::assertCount(2, $variants);
        self::assertSame(1440, $variants[0]['width']);
        self::assertSame('https://example.com/media/7/hero-1440.jpg', $variants[0]['url']);
        self::assertSame('https://example.com/media/7/hero-768.webp', $variants[1]['url']);
    }

    /** A downloadable file lives under /files/, named by its own slug + ext. */
    public function testFilesUseTheFilesRoute(): void
    {
        $file = new Media(
            12, 'file', 'Price list.pdf', 'price-list', 'pdf', 'application/pdf',
            str_repeat('a', 64), 4096, null, null, '', false, 'local',
            'sites/demo/media/originals/12/price-list.pdf', 'ready',
        );

        self::assertSame('/files/12/price-list.pdf', SiteLinks::mediaPath($file));
    }

    /**
     * No variants = nothing on disk to link to. A video (accepted, but the
     * pipeline generates no renditions yet) and an image still processing
     * both answer null rather than a URL that 404s.
     */
    public function testItemsWithNoRenditionsHaveNoUrl(): void
    {
        self::assertNull(SiteLinks::mediaPath($this->image([], kind: 'video')));
        self::assertNull(SiteLinks::mediaUrl($this->site(), $this->image([])));
    }

    /**
     * A replaced item's public filename carries its revision — /media/* is
     * immutable-cached for a year, so the URL has to move when the bytes do.
     */
    public function testReplacedFileUrlCarriesTheRevision(): void
    {
        $file = new Media(
            12, 'file', 'Price list.pdf', 'price-list', 'pdf', 'application/pdf',
            str_repeat('a', 64), 4096, null, null, '', false, 'local',
            'sites/demo/media/originals/12/price-list.pdf', 'ready',
            revision: 3,
        );

        self::assertSame('/files/12/price-list-r3.pdf', SiteLinks::mediaPath($file));
    }

    /**
     * @param list<MediaVariant> $variants
     */
    private function image(array $variants, string $kind = 'image'): Media
    {
        return new Media(
            7, $kind, 'hero.jpg', 'hero', 'jpg', 'image/jpeg',
            str_repeat('b', 64), 100000, 1440, 810, 'a hero', false, 'local',
            'sites/demo/media/originals/7/hero.jpg', 'ready', $variants,
        );
    }
}
