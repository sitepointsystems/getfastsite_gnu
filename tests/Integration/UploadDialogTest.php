<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Handler\FilesActionHandler;
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
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The upload dialog's two server shapes on both the Media and Files action
 * handlers: an `ajax=1` trip carries ONE file and answers JSON (success or
 * the per-file error, never a redirect), while the no-JS fallback submits
 * the whole `files[]` selection in one POST — looped server-side, where one
 * broken file never discards the rest and a batch ignores the single alt
 * field (one typed alt cannot describe several images). Real sharp needed;
 * skipped when absent.
 */
final class UploadDialogTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private DbalMediaRepository $repository;
    private MediaActionHandler $mediaHandler;
    private FilesActionHandler $filesHandler;
    private int $adminId;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        if (!is_file($appRoot . '/node_modules/sharp/package.json')) {
            self::markTestSkipped('sharp not installed — upload tests skipped.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-uploaddlg-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('utest', 'Upload Test', 'https://u.example.com');

        $users = new UserService($this->databases, $clock);
        $this->adminId = $users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');

        $this->repository = new DbalMediaRepository($this->databases, $clock);
        $media = new MediaService($this->repository, $this->databases, new SafeUrlFetcher(), new NullLogger(), new NullImageOptimizer(new NullLogger()), $appRoot);

        // Redis is constructed lazily and the upload path never touches the
        // queue — no connection (and no skip) needed.
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

        $this->mediaHandler = new MediaActionHandler($dash, $media, $queue, new PublishTrigger($queue, $translations));
        $this->filesHandler = new FilesActionHandler($dash, $media, new PublishTrigger($queue, $translations));
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

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 42, 134, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), \strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    /**
     * @param array<string, string>                                                                                  $body
     * @param array<string, \Psr\Http\Message\UploadedFileInterface|list<\Psr\Http\Message\UploadedFileInterface>> $files
     */
    private function post(string $path, array $body, array $files): ServerRequest
    {
        return (new ServerRequest('POST', $path))
            ->withAttribute(SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $this->adminId, 'email' => 'admin@example.com', 'name' => 'Admin', 'role' => 'admin'])
            ->withAttribute('site', 'utest')
            ->withParsedBody($body)
            ->withUploadedFiles($files);
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, 'AJAX trips must answer JSON, never a redirect.');

        return $decoded;
    }

    public function testAjaxTripAnswersJsonAndHonoursAlt(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['ajax' => '1', 'alt' => 'A red door'],
            ['file' => $this->file($this->pngBytes(200, 100), 'door.png')],
        ));

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertTrue($json['success']);
        self::assertSame('image', $json['data']['kind']);
        self::assertSame('A red door', $json['data']['alt']);
        self::assertStringContainsString('data-media-id="' . $json['data']['id'] . '"', $json['data']['embed']);
    }

    public function testAjaxTripReportsTheFilesOwnErrorAsJson(): void
    {
        // Garbage bytes wearing a .png extension — the MIME check rejects it.
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['ajax' => '1'],
            ['file' => $this->file('this is not an image at all', 'fake.png')],
        ));

        self::assertSame(422, $response->getStatusCode());
        $json = $this->json($response);
        self::assertFalse($json['success']);
        self::assertNotSame('', (string) $json['error']['message']);

        // No file at all is a JSON error too, not a redirect.
        $response = $this->mediaHandler->handle($this->post('/sites/utest/media/action', ['ajax' => '1'], []));
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
    }

    public function testFallbackBatchIngestsAllAndIgnoresTheSharedAlt(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['alt' => 'one alt for everything'],
            ['files' => [
                $this->file($this->pngBytes(120, 80), 'first.png'),
                $this->file($this->pngBytes(140, 90), 'second.png'),
            ]],
        ));

        self::assertSame(302, $response->getStatusCode());
        $location = rawurldecode($response->getHeaderLine('Location'));
        self::assertStringContainsString('Uploaded #1, #2.', $location);

        // A batch ignores the single alt field — auto-alt fills each instead.
        foreach ([1, 2] as $id) {
            $item = $this->repository->findById('utest', $id);
            self::assertNotNull($item);
            self::assertNotSame('one alt for everything', $item->alt);
            self::assertNotSame('', $item->alt);
        }

        // A single-file fallback still honours the typed alt.
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['alt' => 'Lone door'],
            ['files' => [$this->file($this->pngBytes(100, 100), 'lone.png')]],
        ));
        self::assertSame(302, $response->getStatusCode());
        $item = $this->repository->findById('utest', 3);
        self::assertNotNull($item);
        self::assertSame('Lone door', $item->alt);
    }

    public function testFallbackBatchSurvivesOneBrokenFile(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            [],
            ['files' => [
                $this->file($this->pngBytes(120, 80), 'good.png'),
                $this->file('garbage', 'broken.png'),
            ]],
        ));

        self::assertSame(302, $response->getStatusCode());
        $location = rawurldecode($response->getHeaderLine('Location'));
        self::assertStringContainsString('Uploaded #1', $location, 'The good file lands despite the broken one.');
        self::assertStringContainsString('broken.png', $location, 'The broken file is named in the flash.');
        self::assertNotNull($this->repository->findById('utest', 1));
        self::assertNull($this->repository->findById('utest', 2));
    }

    public function testAjaxEmbedEscapesTheAltAttribute(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['ajax' => '1', 'alt' => 'A "SALE" banner'],
            ['file' => $this->file($this->pngBytes(80, 80), 'sale.png')],
        ));

        $json = $this->json($response);
        self::assertTrue($json['success']);
        self::assertStringContainsString('alt="A &quot;SALE&quot; banner"', $json['data']['embed']);
    }

    public function testMediaAjaxBouncesANonMediaFileWithAPointer(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['ajax' => '1'],
            ['file' => $this->file("plain text\n", 'notes.txt')],
        ));

        self::assertSame(422, $response->getStatusCode());
        $json = $this->json($response);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Files screen', $json['error']['message']);
    }

    public function testFallbackBatchHonoursDecorativeForEveryFile(): void
    {
        $response = $this->mediaHandler->handle($this->post(
            '/sites/utest/media/action',
            ['decorative' => '1'],
            ['files' => [
                // Different dimensions — identical bytes would sha256-dedupe
                // into ONE row and there would be no #2 to assert on.
                $this->file($this->pngBytes(60, 60), 'spacer-a.png'),
                $this->file($this->pngBytes(61, 60), 'spacer-b.png'),
            ]],
        ));

        self::assertSame(302, $response->getStatusCode());
        foreach ([1, 2] as $id) {
            $item = $this->repository->findById('utest', $id);
            self::assertNotNull($item);
            self::assertSame('', $item->alt, 'Decorative applies to the whole batch — no auto-alt.');
        }
    }

    public function testFallbackBatchFoldsManyFailuresIntoABoundedFlash(): void
    {
        $garbage = [];
        for ($i = 1; $i <= 5; ++$i) {
            $garbage[] = $this->file('not an image ' . $i, 'bad-' . $i . '.png');
        }
        $response = $this->mediaHandler->handle($this->post('/sites/utest/media/action', [], ['files' => $garbage]));

        self::assertSame(302, $response->getStatusCode());
        $location = rawurldecode($response->getHeaderLine('Location'));
        self::assertStringContainsString('and 2 more failed', $location, 'Only the first three failures are spelled out.');
        self::assertStringNotContainsString('bad-4.png', $location);
        self::assertLessThan(2000, \strlen($response->getHeaderLine('Location')), 'The redirect URL stays deliverable.');
    }

    public function testFilesAjaxAcceptsAFileAndRedirectsAnImageToMedia(): void
    {
        $response = $this->filesHandler->handle($this->post(
            '/sites/utest/files/action',
            ['ajax' => '1'],
            ['file' => $this->file("notes body\n", 'notes.txt')],
        ));

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertTrue($json['success']);
        self::assertSame('file', $json['data']['kind']);
        self::assertSame('{{ notes.txt }}', $json['data']['embed']);

        // An image on the Files endpoint is a per-file error that names where
        // it actually landed.
        $response = $this->filesHandler->handle($this->post(
            '/sites/utest/files/action',
            ['ajax' => '1'],
            ['file' => $this->file($this->pngBytes(64, 64), 'photo.png')],
        ));
        self::assertSame(422, $response->getStatusCode());
        $json = $this->json($response);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Media screen', $json['error']['message']);
    }
}
