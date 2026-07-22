<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\RedirectService;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Manual redirects + 404 capture (#15): path normalization, status coercion,
 * upsert, and the missing-path → suggested-redirect flow. Backed by a
 * throwaway site DB (the `redirects` + `missing_paths` tables ship with the
 * site migrations `SiteService::create` runs).
 */
final class RedirectServiceTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private RedirectService $redirects;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-redirect-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $sites = new SiteService(new DbalSiteRepository($this->databases, $clock), $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('rtest', 'Redirect Test', 'https://r.example.com');

        $this->redirects = new RedirectService($this->databases, $clock);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testSaveNormalizesFromPath(): void
    {
        $this->redirects->save('rtest', 'https://r.example.com/old', '/new');
        $this->redirects->save('rtest', 'bare-path', '/target');

        $to = array_column($this->redirects->list('rtest'), 'to_path', 'from_path');
        self::assertArrayHasKey('/old', $to, 'host is stripped to a root-absolute path');
        self::assertArrayHasKey('/bare-path', $to, 'a leading slash is forced');
        self::assertSame('/new', $to['/old']);
    }

    public function testStatusCoercion(): void
    {
        $this->redirects->save('rtest', '/a', '/b', 999);   // invalid → 301
        $this->redirects->save('rtest', '/c', '/d', 302);   // valid → kept

        $status = array_column($this->redirects->list('rtest'), 'status', 'from_path');
        self::assertSame(301, (int) $status['/a']);
        self::assertSame(302, (int) $status['/c']);
    }

    public function testEmptyTargetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->redirects->save('rtest', '/x', '');
    }

    public function testSelfReferentialThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->redirects->save('rtest', '/loop', '/loop');
    }

    public function testUpsertUpdatesInPlace(): void
    {
        $this->redirects->save('rtest', '/p', '/one');
        $this->redirects->save('rtest', '/p', '/two', 302);

        $rows = array_values(array_filter($this->redirects->list('rtest'), static fn (array $r): bool => $r['from_path'] === '/p'));
        self::assertCount(1, $rows, 'ON CONFLICT updates the existing row');
        self::assertSame('/two', $rows[0]['to_path']);
        self::assertSame(302, (int) $rows[0]['status']);
    }

    public function testDelete(): void
    {
        $this->redirects->save('rtest', '/gone', '/home');
        $id = (int) $this->redirects->list('rtest')[0]['id'];
        $this->redirects->delete('rtest', $id);
        self::assertSame([], $this->redirects->list('rtest'));
    }

    public function testMissingPathRecordAndResolve(): void
    {
        $this->redirects->recordMissing('rtest', '/typo');
        $this->redirects->recordMissing('rtest', 'https://r.example.com/typo', 'https://ref.example');

        $missing = $this->redirects->listMissing('rtest');
        self::assertCount(1, $missing, 'the same normalized path collapses to one row');
        self::assertSame('/typo', $missing[0]['path']);
        self::assertSame(2, (int) $missing[0]['hits']);

        $this->redirects->resolveMissing('rtest', (int) $missing[0]['id'], '/fixed');

        self::assertSame([], $this->redirects->listMissing('rtest'), 'resolved rows drop out');
        $to = array_column($this->redirects->list('rtest'), 'to_path', 'from_path');
        self::assertSame('/fixed', $to['/typo'], 'a redirect is created for the captured path');
    }

    public function testClearMissingCreatesNoRedirect(): void
    {
        $this->redirects->recordMissing('rtest', '/skip');
        $this->redirects->clearMissing('rtest', (int) $this->redirects->listMissing('rtest')[0]['id']);

        self::assertSame([], $this->redirects->listMissing('rtest'));
        self::assertSame([], $this->redirects->list('rtest'), 'clearing does not create a redirect');
    }
}
