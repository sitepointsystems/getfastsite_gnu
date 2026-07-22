<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\BuildService;
use PHPUnit\Framework\TestCase;

/**
 * expandYouTube is stateless, so it is exercised through reflection on an
 * uninitialized BuildService — no need to wire the full build dependency graph.
 */
final class YouTubeExpandTest extends TestCase
{
    private function expand(string $html): string
    {
        $service = (new \ReflectionClass(BuildService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BuildService::class, 'expandYouTube');

        return $method->invoke($service, $html);
    }

    public function testAnchorBecomesPlayer(): void
    {
        $out = $this->expand('<p><a href="https://www.youtube.com/watch?v=nFoj9Ppc5r0">Watch</a></p>');

        self::assertStringContainsString('<iframe src="https://www.youtube.com/embed/nFoj9Ppc5r0"', $out);
        self::assertStringContainsString('data-fs-embed', $out);
    }

    public function testBareUrlInParagraphBecomesPlayer(): void
    {
        $out = $this->expand('<p>https://youtu.be/nFoj9Ppc5r0</p>');

        self::assertStringContainsString('<iframe src="https://www.youtube.com/embed/nFoj9Ppc5r0"', $out);
        self::assertStringNotContainsString('<p>', $out);
    }

    public function testParagraphWithAttributesStillMatches(): void
    {
        $out = $this->expand('<p class="video">https://www.youtube.com/embed/nFoj9Ppc5r0</p>');

        self::assertStringContainsString('<iframe src="https://www.youtube.com/embed/nFoj9Ppc5r0"', $out);
    }

    public function testBareUrlAloneInDivKeepsTheContainer(): void
    {
        $out = $this->expand("<div class=\"demo-embed\">https://www.youtube.com/embed/nFoj9Ppc5r0\n        \n      </div>");

        self::assertStringContainsString('<div class="demo-embed"><div data-fs-embed', $out);
        self::assertStringContainsString('<iframe src="https://www.youtube.com/embed/nFoj9Ppc5r0"', $out);
        self::assertStringContainsString('</iframe></div></div>', $out);
    }

    public function testEmbedAndShortsUrlFormsResolve(): void
    {
        self::assertStringContainsString(
            'embed/nFoj9Ppc5r0',
            $this->expand('<p>https://www.youtube.com/shorts/nFoj9Ppc5r0</p>'),
        );
        self::assertStringContainsString(
            'embed/nFoj9Ppc5r0',
            $this->expand('<p>//www.youtube.com/embed/nFoj9Ppc5r0</p>'),
        );
    }

    public function testChannelUrlIsLeftUntouched(): void
    {
        $html = '<p>https://www.youtube.com/@somechannel</p>';

        self::assertSame($html, $this->expand($html));
    }

    public function testDivWithMixedContentIsLeftUntouched(): void
    {
        $html = '<div>Watch this: https://youtu.be/nFoj9Ppc5r0 today</div>';

        self::assertSame($html, $this->expand($html));
    }
}
