<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\HtmlMinifier;
use PHPUnit\Framework\TestCase;

final class HtmlMinifierTest extends TestCase
{
    public function testCollapsesWhitespace(): void
    {
        $html = "<div>\n    <p>hello\n        world</p>\n</div>";

        self::assertSame('<div> <p>hello world</p> </div>', HtmlMinifier::minify($html));
    }

    public function testPreservesPreContent(): void
    {
        $html = "<p>x</p><pre>line1\n    line2</pre>";

        self::assertStringContainsString("line1\n    line2", HtmlMinifier::minify($html));
    }

    public function testStripsComments(): void
    {
        self::assertSame('<p>x</p>', HtmlMinifier::minify('<!-- note --><p>x</p>'));
    }

    public function testKeepsSpaceBetweenInlineElements(): void
    {
        $html = "<strong>a</strong>\n<em>b</em>";

        self::assertSame('<strong>a</strong> <em>b</em>', HtmlMinifier::minify($html));
    }
}
