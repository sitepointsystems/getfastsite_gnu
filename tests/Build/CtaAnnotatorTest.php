<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\CtaAnnotator;
use PHPUnit\Framework\TestCase;

/**
 * The GTM CTA-attribute contract: every link/button gets data-cta (stable,
 * locale-independent), data-cta-text (the actual text), data-cta-language
 * (the page's locale) and data-cta-location (ancestor-inherited); explicit
 * attributes always win; the pass is idempotent; skip regions stay untouched.
 */
final class CtaAnnotatorTest extends TestCase
{
    public function testFillsAllFourAttributesOnAPlainLink(): void
    {
        $html = '<main><a href="/demo" class="btn">Book Demo</a></main>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertStringContainsString('data-cta="demo"', $out);
        self::assertStringContainsString('data-cta-text="Book Demo"', $out);
        self::assertStringContainsString('data-cta-language="en"', $out);
        self::assertStringContainsString('data-cta-location="content"', $out);
        // Original attributes untouched.
        self::assertStringContainsString('href="/demo" class="btn"', $out);
    }

    public function testDataCtaIsIdenticalAcrossLocales(): void
    {
        $locales = ['en', 'da'];
        $en = CtaAnnotator::annotate('<a href="/demo">Book Demo</a>', 'en', $locales);
        $da = CtaAnnotator::annotate('<a href="/da/demo">Book en demo</a>', 'da', $locales);

        self::assertStringContainsString('data-cta="demo"', $en);
        self::assertStringContainsString('data-cta="demo"', $da);
        self::assertStringContainsString('data-cta-text="Book en demo"', $da);
        self::assertStringContainsString('data-cta-language="da"', $da);
    }

    public function testExplicitAttributesAlwaysWin(): void
    {
        $html = '<a href="/demo" data-cta="hero_cta" data-cta-location="hero">Book</a>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertStringContainsString('data-cta="hero_cta"', $out);
        self::assertStringContainsString('data-cta-location="hero"', $out);
        self::assertStringNotContainsString('data-cta="book"', $out);
        // The missing two are still filled.
        self::assertStringContainsString('data-cta-text="Book"', $out);
        self::assertStringContainsString('data-cta-language="en"', $out);
    }

    public function testIdempotent(): void
    {
        $html = '<header><nav><a href="/pricing">Pricing</a></nav></header><main><button>Send</button></main>';
        $once = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertSame($once, CtaAnnotator::annotate($once, 'en', ['en']));
    }

    public function testLocationComesFromLandmarksAndCascadesFromExplicitContainers(): void
    {
        $html = '<header><nav><a href="/pricing">Pricing</a></nav></header>'
            . '<section data-cta-location="hero"><div><a href="/demo">Book</a></div></section>'
            . '<footer><a href="/imprint">Imprint</a></footer>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertMatchesRegularExpression('/href="\/pricing"[^>]*data-cta-location="navigation"/', $out);
        self::assertMatchesRegularExpression('/href="\/demo"[^>]*data-cta-location="hero"/', $out);
        self::assertMatchesRegularExpression('/href="\/imprint"[^>]*data-cta-location="footer"/', $out);
    }

    public function testNearestAncestorWins(): void
    {
        $out = CtaAnnotator::annotate('<footer><nav><a href="/x">X</a></nav></footer>', 'en', ['en']);

        self::assertStringContainsString('data-cta-location="navigation"', $out);
    }

    public function testTocAndLangnavContainersGetTheirOwnLocations(): void
    {
        $out = CtaAnnotator::annotate(
            '<nav data-fs-toc><a href="#intro">Intro</a></nav><nav data-fs-langnav><a href="/da/" hreflang="da">Dansk</a></nav>',
            'en',
            ['en', 'da'],
        );

        self::assertMatchesRegularExpression('/href="#intro"[^>]*data-cta-location="toc"/', $out);
        self::assertMatchesRegularExpression('/hreflang="da"[^>]*data-cta-location="language_switcher"/', $out);
    }

    public function testButtonsInputsAndSpecialHrefs(): void
    {
        $out = CtaAnnotator::annotate(
            '<main><button type="submit">Send besked</button>'
            . '<input type="submit" value="Subscribe">'
            . '<a href="mailto:info@example.com">Write us</a>'
            . '<a href="https://github.com/acme/repo">GitHub</a>'
            . '<a href="/">Home</a></main>',
            'en',
            ['en'],
        );

        self::assertStringContainsString('data-cta="send_besked"', $out);
        self::assertMatchesRegularExpression('/<input[^>]*data-cta="subscribe"[^>]*data-cta-text="Subscribe"/', $out);
        self::assertStringContainsString('data-cta="mailto_info_example_com"', $out);
        self::assertStringContainsString('data-cta="github_com_acme_repo"', $out);
        self::assertStringContainsString('data-cta="home"', $out);
    }

    public function testIconOnlyLinkFallsBackToAriaLabelThenImgAlt(): void
    {
        $out = CtaAnnotator::annotate(
            '<a href="/search" aria-label="Search"><svg><path d="m0 0"/></svg></a>'
            . '<a href="/team"><img src="/media/1/t.webp" alt="The team" width="10" height="10"></a>',
            'en',
            ['en'],
        );

        self::assertMatchesRegularExpression('/href="\/search"[^>]*data-cta-text="Search"/', $out);
        self::assertMatchesRegularExpression('/href="\/team"[^>]*data-cta-text="The team"/', $out);
    }

    public function testSkipRegionsAreNeverTouched(): void
    {
        $html = '<script>var a = \'<a href="/x">no</a>\';</script>'
            . '<noscript><a href="/y">no</a></noscript>'
            . '<template><a href="/z">no</a></template>'
            . '<main><a href="/yes">Yes</a></main>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertStringContainsString('<a href="/x">no</a>', $out);
        self::assertStringContainsString('<a href="/y">no</a>', $out);
        self::assertStringContainsString('<a href="/z">no</a>', $out);
        self::assertMatchesRegularExpression('/href="\/yes"[^>]*data-cta="yes"/', $out);
    }

    public function testTextIsEscapedAndDanishSlugsFollowSiteConventions(): void
    {
        $out = CtaAnnotator::annotate('<button>Køb "nu" & spar</button>', 'da', ['da']);

        self::assertStringContainsString('data-cta="koeb_nu_spar"', $out);
        self::assertStringContainsString('data-cta-text="Køb &quot;nu&quot; &amp; spar"', $out);
    }

    public function testAbbrInsideAnchorDoesNotTruncateTheText(): void
    {
        $out = CtaAnnotator::annotate('<a href="/api"><abbr title="API">API</abbr> docs</a>', 'en', ['en']);

        self::assertStringContainsString('data-cta-text="API docs"', $out);
    }

    public function testQuotedAngleBracketsInAttributesSurvive(): void
    {
        $html = '<div data-fs-component="x" data-values=\'{"a":"1>2"}\'></div><a href="/go">Go</a>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertStringContainsString('data-values=\'{"a":"1>2"}\'', $out);
        self::assertStringContainsString('data-cta="go"', $out);
    }

    public function testMissingListsUnannotatedElementsWithTheSameSkipRules(): void
    {
        $annotated = CtaAnnotator::annotate('<main><a href="/a">A</a><button>B</button></main>', 'en', ['en']);
        self::assertSame([], CtaAnnotator::missing($annotated));

        $missing = CtaAnnotator::missing('<script><a href="/x">no</a></script><main><a href="/a">A</a></main>');
        self::assertCount(1, $missing);
        self::assertStringContainsString('href="/a"', $missing[0]);
    }

    public function testFragmentOnlyAndEmptyLinks(): void
    {
        $out = CtaAnnotator::annotate('<a href="#pricing">See pricing</a><a href="#">Dead</a>', 'en', ['en']);

        self::assertStringContainsString('data-cta="pricing"', $out);
        self::assertStringContainsString('data-cta="dead"', $out);
    }

    public function testHookWrapperLocationBeatsLandmarksTheComponentRenders(): void
    {
        // A component hook's wrapper carries an explicit location; the CTA
        // component happening to render as <aside> must not relabel its links
        // "sidebar" — GTM needs the hook placement.
        $html = '<main><div data-fs-hook="3" data-cta-location="newsletter_cta">'
            . '<aside class="cta"><a href="/signup">Sign up</a></aside></div></main>';
        $out = CtaAnnotator::annotate($html, 'en', ['en']);

        self::assertStringContainsString('data-cta-location="newsletter_cta"', $out);
        self::assertStringNotContainsString('data-cta-location="sidebar"', $out);

        // WITHOUT the hook marker, nearest-ancestor semantics are unchanged:
        // the implied landmark still shadows a farther explicit section.
        $plain = CtaAnnotator::annotate(
            '<section data-cta-location="hero"><aside><a href="/x">X</a></aside></section>',
            'en',
            ['en'],
        );
        self::assertStringContainsString('data-cta-location="sidebar"', $plain);
    }
}
