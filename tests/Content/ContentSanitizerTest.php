<?php

declare(strict_types=1);

namespace Fastsite\Tests\Content;

use Fastsite\Content\Service\ContentSanitizer;
use PHPUnit\Framework\TestCase;

final class ContentSanitizerTest extends TestCase
{
    private ContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ContentSanitizer();
    }

    public function testAllowsContentMarkup(): void
    {
        $html = '<h2>Title</h2><p>Text with <strong>bold</strong> and <a href="/x/">a link</a>.</p>'
            . '<pre><code>$x = 1;</code></pre>';

        $result = $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<h2>Title</h2>', $result->html);
        self::assertStringContainsString('<strong>bold</strong>', $result->html);
        self::assertStringContainsString('href="/x/"', $result->html);
        self::assertSame([], $result->warnings);
    }

    public function testStripsScriptWithWarning(): void
    {
        $result = $this->sanitizer->sanitize('<p>ok</p><script>alert(1)</script>');

        self::assertStringNotContainsString('script', $result->html);
        self::assertStringNotContainsString('alert', $result->html);
        self::assertNotSame([], $result->warnings);
    }

    public function testStripsIframeWithWarning(): void
    {
        $result = $this->sanitizer->sanitize('<iframe src="https://evil.example"></iframe>');

        self::assertStringNotContainsString('iframe', $result->html);
        self::assertNotSame([], $result->warnings);
    }

    public function testStripsStyleAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<p style="color:red">x</p>');

        self::assertStringNotContainsString('style=', $result->html);
        self::assertStringContainsString('<p>x</p>', $result->html);
        self::assertNotSame([], $result->warnings);
    }

    public function testStripsEventHandlers(): void
    {
        $result = $this->sanitizer->sanitize('<p onclick="alert(1)">x</p><a href="javascript:alert(1)">y</a>');

        self::assertStringNotContainsString('onclick', $result->html);
        self::assertStringNotContainsString('javascript:', $result->html);
    }

    public function testKeepsMediaIdAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<img src="/m.jpg" alt="x" data-media-id="12">');

        self::assertStringContainsString('data-media-id="12"', $result->html);
    }

    public function testStripsBareImageWithoutMediaId(): void
    {
        // A bare <img> would ship un-optimised and fail the PageSpeed audit,
        // so it is stripped at ingest with a warning (content never wedges).
        $result = $this->sanitizer->sanitize('<p>a</p><img src="https://cdn.example/x.png" alt="ext"><p>b</p>');

        self::assertStringNotContainsString('<img', $result->html);
        self::assertStringContainsString('<p>a</p>', $result->html);
        self::assertStringContainsString('<p>b</p>', $result->html);
        self::assertNotSame([], $result->warnings);
    }

    public function testDropsEmptyFigureAfterStrippingImage(): void
    {
        $result = $this->sanitizer->sanitize('<figure><img src="/x.png" alt="y"></figure>');

        self::assertStringNotContainsString('<figure', $result->html);
        self::assertStringNotContainsString('<img', $result->html);
    }

    public function testKeepsNavigationLinks(): void
    {
        $result = $this->sanitizer->sanitize('<p>See <a href="https://example.com/">the site</a>.</p>');

        self::assertStringContainsString('href="https://example.com/"', $result->html);
    }

    public function testKeepsArbitraryDataAttributes(): void
    {
        // data-* attributes are inert and site JS (trusted file embeds)
        // hydrates against them — they must never be stripped.
        $result = $this->sanitizer->sanitize(
            '<div data-id="10828" class="rexultz productcard"></div>'
            . '<span data-tooltip-position="top" data-open>x</span>'
        );

        self::assertStringContainsString('data-id="10828"', $result->html);
        self::assertStringContainsString('class="rexultz productcard"', $result->html);
        self::assertStringContainsString('data-tooltip-position="top"', $result->html);
        self::assertStringContainsString('data-open', $result->html);
        self::assertSame([], $result->warnings);
    }

    public function testKeepsDataAttributesInMinifiedAndUnusualMarkup(): void
    {
        // No space before the attribute (minified), JSON values, uppercase name.
        $result = $this->sanitizer->sanitize(
            '<div class="card"data-config=\'{"a":1}\' DATA-REF="x7"><p data-i18n.key="hello">t</p></div>'
        );

        self::assertStringContainsString('data-config=', $result->html);
        self::assertStringContainsString('data-ref="x7"', $result->html);
        self::assertStringContainsString('data-i18n.key="hello"', $result->html);
    }

    public function testDataAttributesDoNotWeakenScriptStripping(): void
    {
        $result = $this->sanitizer->sanitize(
            '<div data-id="1" onclick="alert(1)"><script>alert(2)</script></div>'
        );

        self::assertStringContainsString('data-id="1"', $result->html);
        self::assertStringNotContainsString('onclick', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
    }

    public function testKeepsFileIdEmbed(): void
    {
        // <a data-file-id> is the files-library embed; the build turns it into
        // a crawlable /files/ download link. It must survive sanitization.
        $result = $this->sanitizer->sanitize('<p><a data-file-id="7" download>Datasheet</a></p>');

        self::assertStringContainsString('data-file-id="7"', $result->html);
        self::assertStringContainsString('Datasheet', $result->html);
        self::assertSame([], $result->warnings);
    }
}
