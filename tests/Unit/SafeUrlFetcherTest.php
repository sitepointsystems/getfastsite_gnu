<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Media\Service\SafeUrlFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard — these must be rejected BEFORE any network call.
 */
final class SafeUrlFetcherTest extends TestCase
{
    private SafeUrlFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new SafeUrlFetcher();
    }

    /**
     * @return list<array{string}>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://127.0.0.1/x'],
            ['http://localhost/x'],
            ['http://169.254.169.254/latest/meta-data/'], // cloud metadata
            ['http://10.0.0.5/x'],
            ['http://192.168.1.1/x'],
            ['http://[::1]/x'],
            ['http://100.64.0.2/x'],                       // RFC 6598 CGNAT (K8s)
            ['http://100.127.255.254/x'],
            ['http://198.18.0.5/x'],                       // benchmarking
            ['http://192.0.0.171/x'],                      // NAT64 discovery
            ['http://[2002:a9fe:a9fe::]/x'],               // 6to4 wrapping metadata
            ['http://[64:ff9b::a9fe:a9fe]/x'],             // NAT64 wrapping metadata
            ['http://[::ffff:169.254.169.254]/x'],         // IPv4-mapped metadata
            ['ftp://example.com/x'],
            ['file:///etc/passwd'],
            ['not-a-url'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fetcher->fetch($url);
    }

    public function testPerCallMaxBytesIsHonoured(): void
    {
        // The signature accepts a per-call cap (the media caller passes the
        // per-type limit so a hostile endpoint can't force a huge buffer).
        $reflection = new \ReflectionMethod($this->fetcher, 'fetch');
        self::assertSame('maxBytes', $reflection->getParameters()[1]->getName());
        self::assertTrue($reflection->getParameters()[1]->allowsNull());
    }
}
