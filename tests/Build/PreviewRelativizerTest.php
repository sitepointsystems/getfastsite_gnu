<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\PreviewRelativizer;
use PHPUnit\Framework\TestCase;

/**
 * Preview builds rewrite absolute internal paths to relative by page depth so
 * the tree works from any base. External/protocol-relative/fragment/data URLs
 * are left alone.
 */
final class PreviewRelativizerTest extends TestCase
{
    private const B = 'https://example.com';

    private const SAMPLE = '<a href="/features/">F</a> <a href="/">Home</a>'
        . ' <img src="/media/1/a.png" srcset="/media/1/a.avif 480w, /media/1/b.avif 768w">'
        . ' <link rel="canonical" href="https://example.com/">'
        . ' <a href="#main">skip</a> <a href="https://x.com/">ext</a>'
        . ' <img src="data:image/png;base64,AAAA"> <a href="//cdn.co/x">pr</a>';

    public function testHomeDepthZeroUsesDotSlash(): void
    {
        $r = PreviewRelativizer::relativize(self::SAMPLE, 'index.html', self::B);

        self::assertStringContainsString('href="./features/"', $r);
        self::assertStringContainsString('href="./"', $r);
        self::assertStringContainsString('src="./media/1/a.png"', $r);
        self::assertStringContainsString('srcset="./media/1/a.avif 480w, ./media/1/b.avif 768w"', $r);
    }

    public function testDepthOne(): void
    {
        $r = PreviewRelativizer::relativize(self::SAMPLE, 'features/index.html', self::B);

        self::assertStringContainsString('href="../features/"', $r);
        self::assertStringContainsString('href="../"', $r);
        self::assertStringContainsString('src="../media/1/a.png"', $r);
    }

    public function testDepthTwo(): void
    {
        $r = PreviewRelativizer::relativize(self::SAMPLE, 'blog/slug/index.html', self::B);

        self::assertStringContainsString('href="../../features/"', $r);
        self::assertStringContainsString('srcset="../../media/1/a.avif 480w, ../../media/1/b.avif 768w"', $r);
    }

    public function testLeavesExternalFragmentAndDataUrlsUntouched(): void
    {
        $r = PreviewRelativizer::relativize(self::SAMPLE, 'features/index.html', self::B);

        self::assertStringContainsString('href="#main"', $r);
        self::assertStringContainsString('href="https://x.com/"', $r);
        self::assertStringContainsString('src="data:image/png;base64,AAAA"', $r);
        self::assertStringContainsString('href="//cdn.co/x"', $r);
    }

    /**
     * Inlined shell CSS naming a root-absolute background image used to
     * resolve against the dashboard's origin in preview and 404 — the photo
     * showed only once published.
     */
    public function testRewritesRootAbsoluteCssUrlsInEveryQuoteStyle(): void
    {
        $css = '<style>.a{background-image:url("/media/203/a-480.webp")}'
            . '.b{background:url(\'/media/1/b.jpg\') center}'
            . '.c{background:url(/media/2/c.png)}'
            . '.d{background:URL( "/media/3/d.png" )}'
            . '.e{background:url("https://example.com/media/4/e.png")}</style>';

        $r = PreviewRelativizer::relativize($css, 'blog/slug/index.html', self::B);

        self::assertStringContainsString('url("../../media/203/a-480.webp")', $r);
        self::assertStringContainsString("url('../../media/1/b.jpg')", $r);
        self::assertStringContainsString('url(../../media/2/c.png)', $r);
        self::assertStringContainsString('url("../../media/3/d.png" )', $r);
        self::assertStringContainsString('url("../../media/4/e.png")', $r);

        $home = PreviewRelativizer::relativize($css, 'index.html', self::B);
        self::assertStringContainsString('url("./media/203/a-480.webp")', $home);
    }

    public function testLeavesExternalProtocolRelativeAndDataCssUrlsUntouched(): void
    {
        $css = '<style>.a{background:url(//cdn.co/x.png)}'
            . '.b{background:url("https://x.com/y.png")}'
            . ".c{background:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'%3E%3C/svg%3E\")}"
            . '.d{mask:url(#clip)}</style>';

        $r = PreviewRelativizer::relativize($css, 'features/index.html', self::B);

        self::assertSame($css, $r);
    }
}
