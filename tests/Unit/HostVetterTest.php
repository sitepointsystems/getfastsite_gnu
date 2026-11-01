<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Shared\Net\HostVetter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard shared by media imports and FTP publishing.
 *
 * `vetAll` exists because pinning a single address is a trap: a host with an
 * AAAA record that is not routable from the container hangs until the connect
 * timeout and reports "connection timed out", while the same credentials work
 * instantly from a desktop client on IPv4. Every address is still vetted — the
 * list is a failover path, not a loosening.
 */
final class HostVetterTest extends TestCase
{
    private HostVetter $vetter;

    protected function setUp(): void
    {
        $this->vetter = new HostVetter();
    }

    /**
     * @return list<array{string}>
     */
    public static function blockedHosts(): array
    {
        return [
            ['127.0.0.1'],
            ['10.0.0.5'],
            ['192.168.1.1'],
            ['169.254.169.254'],        // cloud metadata
            ['::1'],
            ['100.64.0.2'],             // RFC 6598 CGNAT
            ['198.18.0.5'],             // benchmarking
            ['192.0.0.171'],            // NAT64 discovery
            ['::ffff:169.254.169.254'], // IPv4-mapped metadata
            ['2002:a9fe:a9fe::'],       // 6to4 wrapping metadata
        ];
    }

    #[DataProvider('blockedHosts')]
    public function testRefusesNonPublicAddresses(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vetter->vetAll($host);
    }

    #[DataProvider('blockedHosts')]
    public function testSingleAddressVetRefusesTheSameHosts(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vetter->vet($host);
    }

    public function testPublicLiteralIsAccepted(): void
    {
        self::assertSame(['203.0.113.10'], $this->vetter->vetAll('203.0.113.10'));
        self::assertSame('203.0.113.10', $this->vetter->vet('203.0.113.10'));
    }

    /** vet() is the head of vetAll(), so they can never disagree. */
    public function testVetReturnsTheFirstOfVetAll(): void
    {
        self::assertSame($this->vetter->vetAll('198.51.100.7')[0], $this->vetter->vet('198.51.100.7'));
    }

    public function testUnresolvableHostIsRefusedRatherThanReturningNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vetter->vetAll('no-such-host.invalid');
    }
}
