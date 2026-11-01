<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Authoring\AuthoringGuideService;
use Fastsite\Build\Figlet\FigletRenderer;
use Fastsite\Build\Service\PageAudit;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;

/** M13: authoring contract, FIGlet banner, apex normalization, new audits. */
final class M13ContractTest extends TestCase
{
    public function testFigletOutputIsPrintableAsciiAndWidthCapped(): void
    {
        $renderer = new FigletRenderer();
        foreach (['FastSite', 'Northwind', 'examplesite', 'AB-9.'] as $name) {
            $lines = $renderer->renderCentered($name, 78);
            self::assertNotEmpty($lines);
            foreach ($lines as $line) {
                self::assertLessThanOrEqual(78, \strlen($line), "line over 78 cols for $name");
                self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $line, "non-ASCII in banner for $name");
            }
        }
    }

    public function testAuthoringGuideGlobalAndPerSite(): void
    {
        $svc = new AuthoringGuideService();

        $global = $svc->guide(null);
        self::assertNull($global['site']);
        self::assertNotEmpty($global['rules']);
        self::assertStringContainsString('Authoring Guide', $global['markdown']);
        // Every rule has the required shape.
        foreach ($global['rules'] as $rule) {
            self::assertArrayHasKey('id', $rule);
            self::assertArrayHasKey('requirement', $rule);
            self::assertArrayHasKey('applies', $rule);
        }
        // Covers the headline SEO/a11y asks.
        $ids = array_column($global['rules'], 'id');
        foreach (['seo-apex', 'seo-crawlable-links', 'seo-auto-files', 'a11y-contrast', 'a11y-link-color', 'perf-cls', 'shell-lang'] as $id) {
            self::assertContains($id, $ids, "missing rule $id");
        }

        $site = new Site(1, 'demo', 'Demo', 'demo.example.com', 'https://demo.example.com', 'da', ['en'], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');
        $perSite = $svc->guide($site);
        self::assertSame('demo', $perSite['site']['handle']);
        self::assertStringContainsString('Primary language:** da', $perSite['markdown']);
        self::assertStringContainsString('data-media-id', $perSite['markdown']);
    }

    public function testNormalizeBaseUrlStripsWww(): void
    {
        self::assertSame('https://example.com', SiteService::normalizeBaseUrl('https://www.example.com/'));
        self::assertSame('https://example.com', SiteService::normalizeBaseUrl('https://example.com'));
        self::assertSame('https://sub.example.com', SiteService::normalizeBaseUrl('https://sub.example.com'));
        self::assertSame('', SiteService::normalizeBaseUrl(''));
        self::assertSame('http://example.com:8080', SiteService::normalizeBaseUrl('http://www.example.com:8080/'));
    }

    public function testPageAuditCrawlableLinksAndCanonicalApex(): void
    {
        $audit = new PageAudit(false, 'example.com');
        $html = '<!doctype html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d">'
            . '<link rel="canonical" href="https://www.example.com/p/"></head><body>'
            . '<a class="skip-link" href="#main">skip</a>'          // OK: in-page anchor
            . '<a href="/real/">real</a>'                            // OK
            . '<a href="#">bad hash</a>'                             // WARN
            . '<a onclick="x()">no href</a>'                         // WARN
            . '<a href="javascript:void(0)">js</a>'                  // WARN
            . '</body></html>';
        $audit->auditPage('page.html', $html);

        $messages = array_map(static fn (array $f): string => $f['message'], $audit->findings());
        $joined = implode("\n", $messages);
        self::assertStringContainsString('www.', $joined, 'canonical www warning missing');
        // The three bad links (#, no-href, javascript:) each WARN "not crawlable".
        self::assertSame(3, substr_count($joined, 'not crawlable'));
        // None of these are FAILs — audits here are content smells.
        self::assertFalse($audit->hasFailures());
    }

    public function testPageAuditSkipLinkIsNotFlagged(): void
    {
        $audit = new PageAudit();
        $html = '<!doctype html><html><head><title>T</title><meta name="description" content="d"></head>'
            . '<body><a href="#main">skip</a><a href="#section-2">jump</a></body></html>';
        $audit->auditPage('p.html', $html);
        $joined = implode("\n", array_map(static fn (array $f): string => $f['message'], $audit->findings()));
        self::assertStringNotContainsString('crawlable', $joined, 'in-page fragment anchors must not be flagged');
    }

    public function testCanonicalApexCheckIsAttributeOrderIndependent(): void
    {
        $audit = new PageAudit(false, 'example.com');
        // href BEFORE rel — an agent shell controls attribute order.
        $html = '<!doctype html><html><head><title>T</title><meta name="description" content="d">'
            . '<link href="https://www.example.com/p/" rel="canonical"></head><body>ok</body></html>';
        $audit->auditPage('p.html', $html);
        $joined = implode("\n", array_map(static fn (array $f): string => $f['message'], $audit->findings()));
        self::assertStringContainsString('www.', $joined, 'href-first www canonical must still WARN');
    }

    /**
     * The check follows the site's chosen canonical host, both ways: on a
     * www-canonical site the www URL is right and the apex one is the
     * duplicate. Hard-coding "apex good, www bad" would fight the operator's
     * own host redirect.
     */
    public function testCanonicalHostCheckFollowsTheSitePreference(): void
    {
        $page = static fn (string $host): string => '<!doctype html><html><head><title>T</title>'
            . '<meta name="description" content="d"><link rel="canonical" href="https://' . $host . '/p/">'
            . '</head><body>ok</body></html>';

        $wwwSite = new PageAudit(false, 'www.example.com');
        $wwwSite->auditPage('ok.html', $page('www.example.com'));
        $wwwSite->auditPage('bad.html', $page('example.com'));

        $messages = implode("\n", array_map(static fn (array $f): string => $f['message'], $wwwSite->findings()));
        self::assertStringNotContainsString('ok.html', implode("\n", array_map(static fn (array $f): string => $f['page'], $wwwSite->findings())));
        self::assertStringContainsString('canonical host is www.example.com', $messages);
        self::assertFalse($wwwSite->hasFailures(), 'a host mismatch is a WARN, never a build failure');
    }

    /** No base URL = no expected host, so the canonical check stands aside. */
    public function testCanonicalHostCheckIsSkippedWithoutABaseUrl(): void
    {
        $audit = new PageAudit();
        $audit->auditPage('p.html', '<!doctype html><html><head><title>T</title><meta name="description" content="d">'
            . '<link rel="canonical" href="https://www.example.com/p/"></head><body>ok</body></html>');

        self::assertSame([], $audit->findings());
    }
}
