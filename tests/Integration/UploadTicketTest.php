<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Media\Service\UploadTicketService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use PHPUnit\Framework\TestCase;

/**
 * Upload tickets: the capability that lets bytes reach the library without
 * passing through a model's output.
 *
 * The properties worth pinning are the ones a careless change would quietly
 * drop — single use, expiry, per-ticket ceiling, and the fact that the token
 * itself is never stored.
 */
final class UploadTicketTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private UploadTicketService $tickets;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-ticket-' . bin2hex(random_bytes(6));
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator(new SystemClock());
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->tickets = new UploadTicketService(
            $this->databases,
            new SystemClock(),
            'https://app.example.com',
        );
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testAMintedTicketCarriesAnAbsoluteUrlAndItsSite(): void
    {
        $ticket = $this->tickets->create('demo', 'oauth', 7, '', 'a hero shot');

        self::assertStringStartsWith('https://app.example.com/u/fsut_', $ticket['upload_url']);
        self::assertSame('demo', $this->tickets->claim($ticket['token'])['site_handle']);
    }

    /**
     * The token is a capability, so only its hash may be at rest: a leaked
     * engine.db must not hand anyone a usable upload URL.
     */
    public function testOnlyTheHashOfTheTokenIsStored(): void
    {
        $ticket = $this->tickets->create('demo', 'api_key', 1);
        $stored = (string) $this->databases->engine()->fetchOne('SELECT token_hash FROM upload_tickets');

        self::assertSame(hash('sha256', $ticket['token']), $stored);
        self::assertStringNotContainsString($ticket['token'], $stored);
    }

    public function testATicketIsSingleUse(): void
    {
        $ticket = $this->tickets->create('demo', 'oauth', 7);
        $this->tickets->claim($ticket['token']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already been used/');
        $this->tickets->claim($ticket['token']);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tickets->claim('fsut_' . bin2hex(random_bytes(32)));
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        // A clock parked far enough in the past that the ticket it mints is
        // already stale by the time the real clock claims it.
        $past = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-01-01T00:00:00Z');
            }

            public function iso(): string
            {
                return '2020-01-01T00:00:00Z';
            }
        };
        $stale = new UploadTicketService($this->databases, $past, 'https://app.example.com');
        $ticket = $stale->create('demo', 'oauth', 7);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expired/');
        $this->tickets->claim($ticket['token']);
    }

    /**
     * A caller may narrow its own ticket but never widen it past the real
     * per-type ceilings, which ingest() enforces again on the bytes.
     */
    public function testTheCeilingCanBeLoweredButNotRaised(): void
    {
        $lowered = $this->tickets->create('demo', 'oauth', 7, '', '', false, 1024);
        self::assertSame(1024, $lowered['max_bytes']);

        $attemptedRaise = $this->tickets->create('demo', 'oauth', 7, '', '', false, PHP_INT_MAX);
        self::assertSame(\Fastsite\Media\Service\MediaService::maxUploadBytes(), $attemptedRaise['max_bytes']);
    }

    /**
     * Pruning rides on minting rather than on a scheduled job, so it has to
     * actually happen there — and must never take a live ticket with it.
     */
    public function testMintingPrunesExpiredTicketsButKeepsLiveOnes(): void
    {
        $past = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-01-01T00:00:00Z');
            }

            public function iso(): string
            {
                return '2020-01-01T00:00:00Z';
            }
        };
        (new UploadTicketService($this->databases, $past, 'https://app.example.com'))->create('demo', 'oauth', 7);
        $live = $this->tickets->create('demo', 'oauth', 7);

        $rows = (int) $this->databases->engine()->fetchOne('SELECT COUNT(*) FROM upload_tickets');
        self::assertSame(1, $rows, 'the stale ticket should have been pruned by the next mint');
        self::assertSame('demo', $this->tickets->claim($live['token'])['site_handle']);
    }
}
