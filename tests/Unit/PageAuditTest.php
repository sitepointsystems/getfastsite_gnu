<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Build\Service\PageAudit;
use PHPUnit\Framework\TestCase;

final class PageAuditTest extends TestCase
{
    private function head(string $body): string
    {
        return '<!doctype html><html><head><title>t</title><meta name="description" content="d"></head><body>' . $body . '</body></html>';
    }

    public function testCleanPagePasses(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('index.html', $this->head('<h1>Hello</h1><picture><img src="/media/1/a.png" width="800" height="600" alt="x"></picture>'));

        self::assertFalse($audit->hasFailures());
    }

    public function testImageWithoutDimensionsFails(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><img src="/a.png" alt="x">'));

        self::assertTrue($audit->hasFailures());
    }

    public function testImageWithoutAltFails(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><img src="/a.png" width="10" height="10">'));

        self::assertTrue($audit->hasFailures());
    }

    public function testScriptFails(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><script>alert(1)</script>'));

        self::assertTrue($audit->hasFailures());
    }

    public function testJsonLdScriptIsAllowed(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><script type="application/ld+json">{}</script>'));

        self::assertFalse($audit->hasFailures());
    }

    public function testExternalResourceFails(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><img src="https://cdn.evil.com/a.png" width="10" height="10" alt="x">'));

        self::assertTrue($audit->hasFailures());
    }

    public function testExternalNavigationLinkIsAllowed(): void
    {
        // A link OUT is not a fetched resource — it must not fail the build.
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>x</h1><p><a href="https://example.com/">go</a></p>'));

        self::assertFalse($audit->hasFailures());
    }

    public function testCanonicalAndPreloadDoNotFail(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html',
            '<!doctype html><html><head><title>t</title><meta name="description" content="d">'
            . '<link rel="canonical" href="https://demo.example.com/blog/x/">'
            . '<link rel="preload" as="image" imagesrcset="/media/1/a.avif 480w">'
            . '</head><body><h1>x</h1></body></html>');

        self::assertFalse($audit->hasFailures());
    }

    public function testOversizeCssFails(): void
    {
        $audit = new PageAudit();
        $audit->auditCss(str_repeat('a', 61 * 1024));

        self::assertTrue($audit->hasFailures());
    }

    public function testInjectedTagManagerBlockIsExempt(): void
    {
        // The real GTM pack: an inline loader in <head> plus a <noscript>
        // <iframe> right after <body>. The iframe is an external fetched
        // resource, so auditing the operator's own opted-in block FAILed every
        // page of the site and made scripts impossible to publish.
        $headBlock = '<script>(function(w,d,s,l,i){w[l]=w[l]||[];})(window,document,"script","dataLayer","GTM-ABC123");</script>' . "\n";
        $bodyBlock = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123" '
            . 'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";

        $audit = new PageAudit(true, '', [$headBlock, $bodyBlock, '']);
        $audit->auditPage('index.html',
            '<!doctype html><html><head><title>t</title><meta name="description" content="d">'
            . $headBlock . '</head><body>' . $bodyBlock . '<h1>x</h1></body></html>');

        self::assertFalse($audit->hasFailures());
    }

    public function testScriptsSiteStillFailsOnExternalContentResource(): void
    {
        // The exemption is the injected block, not the page: a third-party
        // image in the CONTENT of a scripts-enabled site still fails.
        $block = '<script src="https://cdn.example/a.js" async></script>' . "\n";

        $audit = new PageAudit(true, '', [$block, '', '']);
        $audit->auditPage('p.html', $this->head($block . '<h1>x</h1><img src="https://cdn.evil.com/a.png" width="10" height="10" alt="x">'));

        self::assertTrue($audit->hasFailures());
    }

    public function testCtaTrackingFailsOnAnUnannotatedLinkOnlyWhenEnabled(): void
    {
        $bare = $this->head('<h1>x</h1><a href="/demo">Book</a>');

        $off = new PageAudit();
        $off->auditPage('p.html', $bare);
        self::assertFalse($off->hasFailures());

        $on = new PageAudit(false, '', [], true);
        $on->auditPage('p.html', $bare);
        self::assertTrue($on->hasFailures());
        self::assertStringContainsString('CTA tracking', $on->findings()[0]['message']);
    }

    public function testCtaTrackingPassesOnTheAnnotatorsOwnOutput(): void
    {
        $annotated = \Fastsite\Build\Service\CtaAnnotator::annotate(
            $this->head('<h1>x</h1><header><nav><a href="/demo">Book</a></nav></header><main><button>Send</button></main>'),
            'en',
            ['en'],
        );

        $audit = new PageAudit(false, '', [], true);
        $audit->auditPage('p.html', $annotated);
        self::assertFalse($audit->hasFailures());
    }

    public function testCtaTrackingIgnoresInjectedUserScriptBlocks(): void
    {
        // User script blocks are injected AFTER the annotator ran, so markup
        // inside them (GTM's noscript iframe wraps nothing annotatable, but a
        // pasted widget block may hold links) must not fail the audit.
        $block = '<noscript><a href="https://example.com/x">tracking fallback</a></noscript>' . "\n"
            . '<div><a href="/vendor-widget">widget</a></div>' . "\n";

        $audit = new PageAudit(true, '', [$block, '', ''], true);
        $audit->auditPage('p.html', $this->head($block . '<h1>x</h1>'));
        self::assertFalse($audit->hasFailures());
    }

    public function testMultipleH1IsWarnNotFail(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>a</h1><h1>b</h1>'));

        self::assertFalse($audit->hasFailures());
        self::assertNotEmpty($audit->findings());
    }
}
