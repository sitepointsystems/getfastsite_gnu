<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Handler\MediaActionHandler;
use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Media\NullImageOptimizer;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The media screen's selection actions.
 *
 * The contract that matters is partial failure: one id somebody else already
 * purged in another tab must not take the other nineteen down with it — the
 * same guarantee the upload batch gives, and for the same reason (the user
 * did the work of selecting them).
 */
final class MediaBulkActionTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private MediaService $media;
    private DbalMediaRepository $repository;
    private MediaActionHandler $handler;
    private Site $site;
    private int $adminId;
    private int $seq = 0;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — needs real variants.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD not available.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-bulk-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->site = $sites->create('bulk', 'Bulk', 'https://bulk.example.com');

        $this->repository = new DbalMediaRepository($this->databases, $clock);
        $this->media = new MediaService($this->repository, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);

        $users = new UserService($this->databases, $clock);
        $this->adminId = $users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');

        // Redis is constructed lazily; only the optimize path reaches it.
        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        $queue = new JobQueue($this->databases, $redis, $clock);
        $translations = new \Fastsite\Translation\Service\TranslationService(
            new \Fastsite\Content\Repository\DbalContentRepository($this->databases, $clock),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            new \Fastsite\Tests\Support\UnusedAiClientFactory(),
            new \Fastsite\Content\Service\ContentSanitizer(),
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $clock),
        );
        $dash = new Dash(
            new EngineView($appRoot),
            new SessionService($this->databases, $redis, $clock),
            $siteRepo,
            $this->databases,
            new TeamService($this->databases, $clock),
            new WorkspaceService($this->databases, $clock),
            str_repeat('s', 32),
        );
        $this->handler = new MediaActionHandler($dash, $this->media, $queue, new PublishTrigger($queue, $translations));
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

    public function testBulkDeleteThenRestoreRoundTripsTheWholeSelection(): void
    {
        $ids = [$this->image('a.png')->id, $this->image('b.png')->id, $this->image('c.png')->id];

        $this->post('bulk-delete', $ids);
        foreach ($ids as $id) {
            self::assertNotNull($this->repository->findAnyById('bulk', $id)?->deletedAt, '#' . $id . ' should be trashed');
        }

        $this->post('bulk-restore', $ids);
        foreach ($ids as $id) {
            self::assertNull($this->repository->findAnyById('bulk', $id)?->deletedAt, '#' . $id . ' should be back');
        }
    }

    public function testBulkPurgeRemovesTheRowsForGood(): void
    {
        $ids = [$this->image('a.png')->id, $this->image('b.png')->id];
        $this->post('bulk-delete', $ids);
        $this->post('bulk-purge', $ids);

        foreach ($ids as $id) {
            self::assertNull($this->repository->findAnyById('bulk', $id), '#' . $id . ' should be gone');
        }
    }

    /**
     * The point of the loop: a dead id is reported, the live ones still act.
     * Doing it the other way — abandoning the batch on the first failure —
     * discards work the user explicitly selected.
     */
    public function testOneBadIdDoesNotDiscardTheRest(): void
    {
        $good = [$this->image('a.png')->id, $this->image('b.png')->id];
        $response = $this->post('bulk-delete', [...$good, 999999]);

        foreach ($good as $id) {
            self::assertNotNull($this->repository->findAnyById('bulk', $id)?->deletedAt);
        }
        // Redirected with a message rather than blowing up.
        self::assertSame(302, $response->getStatusCode());
    }

    /** Ids are deduped — a doubled checkbox must not act twice. */
    public function testDuplicateIdsActOnce(): void
    {
        $id = $this->image('a.png')->id;
        $response = $this->post('bulk-delete', [$id, $id, $id]);

        self::assertStringContainsString('Moved 1 item(s)', urldecode((string) $response->getHeaderLine('Location')));
        self::assertNotNull($this->repository->findAnyById('bulk', $id)?->deletedAt);
    }

    /**
     * The bulk bar renders with JS off, so an empty submit is a normal thing
     * a person can do — it gets an instruction, not a stack trace.
     */
    public function testEmptySelectionIsAnInstructionNotAnError(): void
    {
        $response = $this->post('bulk-delete', []);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('err=', (string) $response->getHeaderLine('Location'));
    }

    /**
     * Bulk optimize hands the SELECTION to the queued job (25 ShortPixel
     * round-trips would outlive nginx's read timeout), and deliberately
     * carries no dedupe key — `optimize:{site}` would absorb it into a
     * pending "Optimize all" twin and replace these ids with that job's.
     */
    public function testBulkOptimizeQueuesTheSelectedIds(): void
    {
        try {
            (new \Predis\Client('tcp://127.0.0.1:6379/15'))->ping();
        } catch (\Throwable) {
            self::markTestSkipped('Redis not available.');
        }

        $ids = [$this->image('a.png')->id, $this->image('b.png')->id];
        $this->post('bulk-optimize', $ids);

        $rows = $this->databases->engine()->fetchAllAssociative(
            "SELECT payload FROM jobs WHERE type = 'optimize_media' AND site_handle = 'bulk'",
        );
        self::assertCount(1, $rows);
        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertIsArray($payload);
        self::assertSame($ids, array_map(intval(...), $payload['ids']));
    }

    /** @param list<int> $ids */
    private function post(string $action, array $ids): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('POST', '/sites/bulk/media/action'))
            ->withAttribute(SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $this->adminId, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'admin'])
            ->withAttribute('site', 'bulk')
            ->withParsedBody(['action' => $action, 'ids' => array_map(strval(...), $ids)]);

        return $this->handler->handle($request);
    }

    /**
     * Each image must be BYTE-DISTINCT or the pipeline's sha256 dedupe hands
     * back the same row every time — which silently turned "three images"
     * into one item selected three times, and made this whole file look
     * green while testing almost nothing. The dimensions vary per call.
     */
    private function image(string $name): \Fastsite\Media\Dto\Media
    {
        return $this->media->ingest($this->site, $this->pngBytes(400 + (++$this->seq * 7), 300), $name, 'alt for ' . $name);
    }

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 42, 134, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
