<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\HtaccessBuilder;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Service\RedirectService;
use PHPUnit\Framework\TestCase;

/**
 * The .htaccess is what stops an ftp-published site from silently losing the
 * CSP, the immutable caching and the canonical 301 that `_headers` and
 * `_redirects` give every other mode.
 */
final class HtaccessBuilderTest extends TestCase
{
    private HtaccessBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HtaccessBuilder();
    }

    private function site(string $canonicalHost = 'apex', bool $development = false): Site
    {
        return new Site(
            1,
            'demo',
            'Demo',
            'example.com',
            'https://example.com',
            'en',
            [],
            '/blog/{slug}/',
            '/{slug}/',
            '',
            'main',
            '',
            1,
            'active',
            canonicalHost: $canonicalHost,
            serveMode: 'ftp',
            development: $development,
        );
    }

    /**
     * The whole point of passing the CSP in rather than rebuilding it: two
     * copies would drift the first time one was relaxed.
     */
    public function testCspMatchesTheHeadersFileForTheSameScriptMode(): void
    {
        foreach (['none', 'inline', 'full'] as $mode) {
            $csp = BuildService::csp($mode);
            $out = $this->builder->build($this->site(), [], $csp);

            self::assertStringContainsString(
                'Header always set Content-Security-Policy "' . $csp . '"',
                $out,
                'The .htaccess CSP must be the same string _headers ships for mode ' . $mode . '.',
            );
        }
    }

    public function testShipsSecurityHeadersAndPathScopedImmutableCaching(): void
    {
        $out = $this->builder->build($this->site(), [], BuildService::csp('none'));

        self::assertStringContainsString('X-Content-Type-Options "nosniff"', $out);
        self::assertStringContainsString('Referrer-Policy "strict-origin-when-cross-origin"', $out);
        // Scoped by path, exactly as _headers does — /assets/ /media/ /files/
        // are immutable because their names carry a hash or a media id, which
        // is not a property of the file extension.
        self::assertStringContainsString('SetEnvIf Request_URI "^/(assets|media|files)/" FS_IMMUTABLE=1', $out);
        self::assertStringContainsString('Cache-Control "public, max-age=31536000, immutable" env=FS_IMMUTABLE', $out);
    }

    /** Without this the CUSTOMER'S host serves its own 404, not the built one. */
    public function testPointsApacheAtTheBuilt404Page(): void
    {
        self::assertStringContainsString(
            'ErrorDocument 404 /404.html',
            $this->builder->build($this->site(), [], BuildService::csp('none')),
        );
    }

    /**
     * A real 410 — the thing `_redirects` structurally cannot express and has
     * to ship as a comment.
     */
    public function testGoneEmitsARealApache410(): void
    {
        $out = $this->builder->build($this->site(), [
            ['from_path' => '/retired', 'to_path' => '', 'status' => RedirectService::GONE],
        ], BuildService::csp('none'));

        self::assertMatchesRegularExpression('#RedirectMatch 410 \^/retired#', $out);
        self::assertStringNotContainsString('# /retired 410', $out, 'A 410 must be a rule here, not a comment.');
    }

    /**
     * mod_alias's `Redirect` matches by PREFIX, so an unanchored rule for /a
     * would also fire on /about. Anchored RedirectMatch is the only correct
     * translation.
     */
    public function testRedirectsAreAnchoredSoTheyCannotOverMatch(): void
    {
        $out = $this->builder->build($this->site(), [
            ['from_path' => '/a', 'to_path' => '/b', 'status' => 301],
        ], BuildService::csp('none'));

        self::assertStringContainsString('RedirectMatch 301 ^/a/?$ /b', $out);
        self::assertStringNotContainsString("\nRedirect 301 /a", $out);
    }

    public function testRegexMetacharactersInAPathAreEscaped(): void
    {
        $out = $this->builder->build($this->site(), [
            ['from_path' => '/pricing.old', 'to_path' => '/pricing', 'status' => 301],
        ], BuildService::csp('none'));

        // The dot must be a literal dot, not "any character".
        self::assertStringContainsString('^/pricing\.old/?$', $out);
    }

    public function testCanonicalRuleFiresOnBothTheWrongHostAndPlainHttp(): void
    {
        $out = $this->builder->build($this->site(), [], BuildService::csp('none'));

        self::assertStringContainsString('RewriteCond %{HTTP_HOST} !^example\.com$ [NC,OR]', $out);
        self::assertStringContainsString('RewriteCond %{HTTPS} !=on', $out);
        // Behind a proxy %{HTTPS} is off even on a working https request.
        self::assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !=https', $out);
        self::assertStringContainsString('RewriteRule ^ https://example.com%{REQUEST_URI} [L,R=301]', $out);
    }

    /** The 301 must target whichever host the canonical tags actually name. */
    public function testCanonicalRuleFollowsTheWwwChoice(): void
    {
        $out = $this->builder->build($this->site('www'), [], BuildService::csp('none'));

        self::assertStringContainsString('!^www\.example\.com$', $out);
        self::assertStringContainsString('RewriteRule ^ https://www.example.com%{REQUEST_URI}', $out);
    }

    /**
     * .htaccess is line-oriented and header values land inside a quoted
     * directive, so an injected newline or quote would let stored content
     * append arbitrary configuration to a customer's web server.
     */
    public function testHeaderValueCannotBreakOutOfTheDirective(): void
    {
        $out = $this->builder->build(
            $this->site(),
            [],
            "script-src 'none'\"\nHeader always set X-Evil \"pwned",
        );

        // The hostile text is not removed — it is NEUTRALIZED, staying inside
        // the CSP's own quotes as inert value text. What must never happen is
        // it becoming a directive of its own, which needs either a newline or
        // a closing quote to pull off.
        $directives = 0;
        foreach (explode("\n", $out) as $line) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*Header\s+always\s+set\s+X-Evil/',
                $line,
                'An injected header must never become its own directive.',
            );
            if (str_contains($line, 'Content-Security-Policy')) {
                ++$directives;
                self::assertSame(2, substr_count($line, '"'), 'The CSP directive must carry exactly its own two quotes.');
            }
        }
        self::assertSame(1, $directives, 'Exactly one CSP directive, on exactly one line.');
    }

    public function testNoRedirectSectionWhenThereAreNoRules(): void
    {
        self::assertStringNotContainsString(
            'RedirectMatch',
            $this->builder->build($this->site(), [], BuildService::csp('none')),
        );
    }

    /**
     * A development site published to a customer's own host has no `_headers`
     * to fall back on — this file is the only thing setting response headers
     * there. Without it the pages are still noindexed by their meta tag, but
     * every PDF and image in the build is perfectly indexable.
     */
    public function testDevelopmentModeShipsTheRobotsHeader(): void
    {
        $csp = BuildService::csp('none');

        self::assertStringContainsString(
            'Header always set X-Robots-Tag "noindex, nofollow"',
            $this->builder->build($this->site(development: true), [], $csp),
        );
        self::assertStringNotContainsString(
            'X-Robots-Tag',
            $this->builder->build($this->site(), [], $csp),
        );
    }
}
