<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Site\Service\SiteScriptService;
use PHPUnit\Framework\TestCase;

/**
 * Site scripts are injected into every page, and the field used to ship
 * verbatim unless it was a bare URL — so JavaScript pasted without its
 * `<script>` tags was emitted as naked source text into the document: the code
 * never ran, and the source rendered onto the page. The normalizer gives loose
 * JavaScript the tags it lost while leaving every element the author actually
 * wrote exactly as written.
 */
final class SiteScriptNormalizeTest extends TestCase
{
    public function testPureJavaScriptIsWrapped(): void
    {
        $code = "(function () {\n  window.dataLayer = window.dataLayer || [];\n})();";

        self::assertSame('<script>' . $code . '</script>', SiteScriptService::normalize($code));
        self::assertTrue(SiteScriptService::needsWrapping($code));
    }

    public function testAuthoredScriptElementsAreLeftAloneAndNeverDoubleWrapped(): void
    {
        $code = '<script src="https://cdn.example.com/consent.js" data-key="abc"></script>';

        self::assertSame($code, SiteScriptService::normalize($code));
        self::assertFalse(SiteScriptService::needsWrapping($code));
    }

    /** The reported failure: a real loader tag followed by a bare IIFE. */
    public function testLoaderTagFollowedByLooseJavaScriptWrapsOnlyTheLooseHalf(): void
    {
        $loader = '<script src="https://cdn.example.com/consent.js" data-key="abc"></script>';
        $inline = '(function () { "use strict"; var COOKIE_NAME = "c"; })();';

        $out = SiteScriptService::normalize($loader . "\n" . $inline);

        self::assertSame($loader . "\n" . '<script>' . $inline . '</script>', $out);
        // The loader survives intact and the inline half now actually runs.
        self::assertSame(2, substr_count($out, '<script'));
        self::assertStringNotContainsString('</script>(', $out);
    }

    /**
     * The reason this is a scanner and not a regex: `<` inside a script body is
     * a comparison operator, and treating it as a tag would corrupt the code.
     */
    public function testComparisonOperatorsInsideAndOutsideScriptsAreNotMarkup(): void
    {
        $authored = '<script>for (var i = 0; i < items.length; i++) { total += i; }</script>';
        self::assertSame($authored, SiteScriptService::normalize($authored));

        $loose = 'for (var i = 0; i < 10; i++) { go(i); }';
        self::assertSame('<script>' . $loose . '</script>', SiteScriptService::normalize($loose));
    }

    /**
     * The unit of "leave it alone" is the ELEMENT, not the tag. Consuming
     * tag-by-tag made every text node look loose, so a `<noscript>` fallback
     * and a consent banner's visible copy came back wrapped in `<script>` —
     * the text disappeared from every page and invalid inline scripts shipped
     * in its place. This is markup the verbatim path handled correctly before,
     * so it is a regression guard, not a new feature.
     */
    public function testTextInsideAuthoredElementsIsNeverWrapped(): void
    {
        $fallback = '<noscript>Please enable JavaScript to use this site.</noscript>';
        self::assertSame($fallback, SiteScriptService::normalize($fallback));
        self::assertFalse(SiteScriptService::needsWrapping($fallback));

        $banner = '<div id="cc-banner">We use cookies. <button onclick="ok()">OK</button></div>';
        self::assertSame($banner, SiteScriptService::normalize($banner));
        self::assertFalse(SiteScriptService::needsWrapping($banner));

        // An inner element of the same name must not close the outer one.
        $nested = '<div class="a">outer <div class="b">inner</div> tail</div>';
        self::assertSame($nested, SiteScriptService::normalize($nested));
    }

    /**
     * Minified JavaScript is exactly what people paste, and minifiers never
     * put spaces around `<`. Reading `<a` in `i<a.length` as a tag cut the code
     * into broken fragments and rendered its tail as visible page text.
     */
    public function testMinifiedComparisonsAreNotMistakenForTags(): void
    {
        $minified = 'for(var i=0;i<a.length;i++){if(a[i]>0)b()}';
        self::assertSame('<script>' . $minified . '</script>', SiteScriptService::normalize($minified));

        $tight = 'if(x<y){go()}';
        self::assertSame('<script>' . $tight . '</script>', SiteScriptService::normalize($tight));

        // A tag name must be followed by whitespace, '>' or '/' to be a tag.
        $mixed = '<span class="x">keep</span>' . "\n" . 'if(i<b.len){go()}';
        self::assertSame('<span class="x">keep</span>' . "\n" . '<script>if(i<b.len){go()}</script>', SiteScriptService::normalize($mixed));
    }

    public function testMarkupOnlySnippetsPassThroughUntouched(): void
    {
        // GTM's body snippet is markup, not script — wrapping it would break it.
        $gtm = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XYZ"'
            . ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
        self::assertSame($gtm, SiteScriptService::normalize($gtm));
        self::assertFalse(SiteScriptService::needsWrapping($gtm));

        $commented = "<!-- Google tag -->\n" . '<script src="https://x.example/t.js"></script>';
        self::assertSame($commented, SiteScriptService::normalize($commented));
    }

    public function testNormalizationIsIdempotent(): void
    {
        $code = '<script src="https://x.example/a.js"></script>' . "\n" . 'init({ mode: "auto" });';
        $once = SiteScriptService::normalize($code);

        self::assertSame($once, SiteScriptService::normalize($once));
        self::assertFalse(SiteScriptService::needsWrapping($once));
    }

    public function testQuotedAttributeContainingAngleBracketsDoesNotEndTheTag(): void
    {
        $code = '<script src="https://x.example/t.js?q=a%3Eb" data-x="a>b"></script>' . "\n" . 'go();';

        self::assertSame(
            '<script src="https://x.example/t.js?q=a%3Eb" data-x="a>b"></script>' . "\n" . '<script>go();</script>',
            SiteScriptService::normalize($code),
        );
    }

    public function testSurroundingWhitespaceIsPreservedOutsideTheWrapper(): void
    {
        $out = SiteScriptService::normalize("<span></span>\n\n  go();\n\n");

        self::assertSame("<span></span>\n\n  <script>go();</script>\n\n", $out);
    }

    public function testAnUnclosedScriptSwallowsTheRestRatherThanRewrappingIt(): void
    {
        // Author forgot the closing tag: browsers treat the rest as the body,
        // and so must we — re-wrapping it would nest <script> inside <script>.
        $code = '<script>go();';

        self::assertSame($code, SiteScriptService::normalize($code));
    }

    public function testEmptyAndWhitespaceOnlyCodeIsUnchanged(): void
    {
        self::assertSame('', SiteScriptService::normalize(''));
        self::assertSame("\n  \n", SiteScriptService::normalize("\n  \n"));
        self::assertFalse(SiteScriptService::needsWrapping("\n  \n"));
    }

    public function testABareUrlIsNotTreatedAsLooseJavaScript(): void
    {
        // It becomes a <script src> at render time; the card must not claim
        // the build had to wrap anything.
        self::assertFalse(SiteScriptService::needsWrapping('https://cdn.example.com/t.js'));
    }
}
