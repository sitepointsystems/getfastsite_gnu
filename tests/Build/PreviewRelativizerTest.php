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
    private const B = 'https://rexultz.com';

    private const SAMPLE = '<a href="/features/">F</a> <a href="/">Home</a>'
        . ' <img src="/media/1/a.png" srcset="/media/1/a.avif 480w, /media/1/b.avif 768w">'
        . ' <link rel="canonical" href="https://rexultz.com/">'
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
}
