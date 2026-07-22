<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Http\PublicUrl;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * PublicUrl decides the external origin advertised in OAuth discovery. If it
 * points at localhost, remote MCP clients (claude.ai) can't register — so it
 * must derive the real public origin from the request behind the proxy.
 */
final class PublicUrlTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function req(string $uri, array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', $uri, $headers);
    }

    public function testExplicitPublicAppUrlWins(): void
    {
        $r = $this->req('http://internal:8080/mcp', ['Host' => 'internal:8080']);
        self::assertSame('https://mcp.example.com', PublicUrl::base($r, 'https://mcp.example.com'));
    }

    public function testDerivesFromForwardedHeadersWhenAppUrlIsLocalhost(): void
    {
        $r = $this->req('http://127.0.0.1:8080/mcp', [
            'Host' => '127.0.0.1:8080',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'mcp.example.com',
        ]);
        // Default/localhost APP_URL → derive the real public origin.
        self::assertSame('https://mcp.example.com', PublicUrl::base($r, 'http://localhost:8080'));
    }

    public function testDerivesFromHostHeaderWhenNoForwardedHost(): void
    {
        $r = $this->req('http://x/mcp', ['Host' => 'fast.example.com', 'X-Forwarded-Proto' => 'https']);
        self::assertSame('https://fast.example.com', PublicUrl::base($r, ''));
    }

    public function testMultiValueHostTakesFirstToken(): void
    {
        // getHeaderLine can comma-join duplicate Host headers.
        $r = $this->req('http://x/mcp')->withHeader('Host', ['a.example.com', 'b.example.com']);
        self::assertSame('https://a.example.com', PublicUrl::base($r->withHeader('X-Forwarded-Proto', 'https'), ''));
    }

    public function testMalformedHostFallsBack(): void
    {
        $r = $this->req('http://x/mcp')->withHeader('Host', 'not a host name');
        // Bogus host rejected → falls back to the configured value.
        self::assertSame('https://safe.example.com', PublicUrl::base($r, 'https://safe.example.com'));
    }

    public function testEmptyEverythingFallsBack(): void
    {
        $r = new ServerRequest('GET', '/mcp'); // relative URI → no host anywhere
        self::assertSame('http://localhost', PublicUrl::base($r, ''));
    }
}
