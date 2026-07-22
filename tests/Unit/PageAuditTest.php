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

    public function testMultipleH1IsWarnNotFail(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', $this->head('<h1>a</h1><h1>b</h1>'));

        self::assertFalse($audit->hasFailures());
        self::assertNotEmpty($audit->findings());
    }
}
