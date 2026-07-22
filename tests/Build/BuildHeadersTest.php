<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\BuildService;
use PHPUnit\Framework\TestCase;

/**
 * The per-mode CSP emitted into _headers. 'full' must allow blob: — embed
 * loaders (chat widgets, affiliate embeds) boot their payload from a blob:
 * URL, and script-src is the fallback for script-src-elem, so without it the
 * browser blocks the very scripts the site opted in to ship.
 */
final class BuildHeadersTest extends TestCase
{
    private function headers(string $mode): string
    {
        $service = (new \ReflectionClass(BuildService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(BuildService::class, 'headers');

        return (string) $m->invoke($service, $mode);
    }

    public function testZeroJsDefaultBlocksAllScripts(): void
    {
        self::assertStringContainsString("script-src 'none'", $this->headers('none'));
    }

    public function testFullModeAllowsHttpsAndBlobScripts(): void
    {
        $csp = $this->headers('full');

        self::assertStringContainsString("script-src 'self' 'unsafe-inline' https: blob:", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function testInlineModeAllowsOnlyInline(): void
    {
        $csp = $this->headers('inline');

        self::assertStringContainsString("script-src 'unsafe-inline';", $csp);
        self::assertStringNotContainsString('blob:', $csp);
    }
}
