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
 * Filtering, sorting and bulk-resolving captured 404s.
 *
 * Built for a real case: a ported site had hundreds of dead /media/ paths in
 * Suggested redirects, and the card offered one button per row — so clearing
 * them was one click each, forever.
 *
 * NOTE on the single-publish guarantee: `resolveMissingMany` deliberately does
 * NOT publish. The one publish per batch lives in SettingsActionHandler, after
 * the loop, which is structural rather than something these tests can observe.
 */
final class RedirectMissingBulkTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private RedirectService $redirects;
    private string $handle = 'demo';

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-missing-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        (new SiteService(new DbalSiteRepository($this->databases, $clock), $this->databases, $migrator, $clock, new NullLogger(), $appRoot))
            ->create($this->handle, 'Demo', 'https://demo.example');

        $this->redirects = new RedirectService($this->databases, $clock);
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
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testTheFilterNarrowsToASubstringAndTheCountIsHonest(): void
    {
        $this->seed('/media/a.jpg', '/media/b.jpg', '/blog/old-post/', '/kontakt/');

        self::assertCount(4, $this->redirects->listMissing($this->handle));
        self::assertSame(4, $this->redirects->countMissing($this->handle));

        $media = $this->redirects->listMissing($this->handle, '/media/');
        self::assertCount(2, $media);
        self::assertSame(2, $this->redirects->countMissing($this->handle, '/media/'));
        // Asked for by path, because the default order is hits then MOST
        // RECENT — with equal hits that is seed order reversed, which is
        // correct and not something to assert against.
        self::assertSame(
            ['/media/a.jpg', '/media/b.jpg'],
            $this->paths($this->redirects->listMissing($this->handle, '/media/', 'path')),
        );
    }

    public function testTheFilterTreatsLikeWildcardsAsLiteralText(): void
    {
        // `_` is a LIKE wildcard. Someone filtering for a path that contains
        // one must not silently select every path with any character there.
        $this->seed('/media/a_b.jpg', '/media/axb.jpg');

        $hits = $this->redirects->listMissing($this->handle, 'a_b');

        self::assertSame(['/media/a_b.jpg'], $this->paths($hits));
        self::assertSame(1, $this->redirects->countMissing($this->handle, 'a_b'));
    }

    public function testSortOrdersTheList(): void
    {
        $this->seed('/zebra/');
        $this->seed('/alpha/');
        $this->redirects->recordMissing($this->handle, '/zebra/'); // 2 hits

        self::assertSame(['/zebra/', '/alpha/'], $this->paths($this->redirects->listMissing($this->handle, '', 'hits')));
        self::assertSame(['/alpha/', '/zebra/'], $this->paths($this->redirects->listMissing($this->handle, '', 'path')));
        // An unknown sort falls back rather than erroring, same as an unknown tab.
        self::assertSame(['/zebra/', '/alpha/'], $this->paths($this->redirects->listMissing($this->handle, '', 'nonsense')));
    }

    public function testBulkDismissClearsOnlyTheIdsGiven(): void
    {
        $this->seed('/media/a.jpg', '/media/b.jpg', '/keep-me/');
        $media = $this->redirects->listMissing($this->handle, '/media/');

        $n = $this->redirects->clearMissingMany($this->handle, array_column($media, 'id'));

        self::assertSame(2, $n);
        self::assertSame(['/keep-me/'], $this->paths($this->redirects->listMissing($this->handle)));
        // Dismissing writes no rules: nothing about the published site changes.
        self::assertSame([], $this->redirects->list($this->handle));
    }

    public function testBulkGoneWritesOneRulePerPathAndResolvesThem(): void
    {
        $this->seed('/media/a.jpg', '/media/b.jpg', '/keep-me/');
        $media = $this->redirects->listMissing($this->handle, '/media/');

        $n = $this->redirects->resolveMissingMany($this->handle, array_column($media, 'id'), RedirectService::GONE);

        self::assertSame(2, $n);
        self::assertSame(['/keep-me/'], $this->paths($this->redirects->listMissing($this->handle)));

        $rules = $this->redirects->list($this->handle);
        self::assertCount(2, $rules);
        foreach ($rules as $rule) {
            self::assertSame(410, (int) $rule['status']);
            self::assertSame('', (string) $rule['to_path'], '410 has no successor');
        }
    }

    public function testBulkIgnoresRubbishAndDuplicateIds(): void
    {
        $this->seed('/media/a.jpg');
        $id = $this->redirects->listMissing($this->handle)[0]['id'];

        // Duplicated, zeroed, negative and non-numeric all arrive from a form
        // POST sooner or later; none of them may make the batch throw.
        $n = $this->redirects->clearMissingMany($this->handle, [$id, $id, 0, -3, 'x', 999999]);

        self::assertSame(1, $n, 'the one real row, counted once');
        self::assertSame([], $this->redirects->listMissing($this->handle));
    }

    public function testAnEmptySelectionIsANoOpRatherThanAnError(): void
    {
        $this->seed('/media/a.jpg');

        self::assertSame(0, $this->redirects->clearMissingMany($this->handle, []));
        self::assertSame(0, $this->redirects->resolveMissingMany($this->handle, []));
        self::assertCount(1, $this->redirects->listMissing($this->handle), 'nothing was touched');
    }

    private function seed(string ...$paths): void
    {
        foreach ($paths as $path) {
            $this->redirects->recordMissing($this->handle, $path);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function paths(array $rows): array
    {
        return array_map(static fn (array $r): string => (string) $r['path'], $rows);
    }
}
