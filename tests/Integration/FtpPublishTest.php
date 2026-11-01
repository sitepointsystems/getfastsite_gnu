<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Publish\Service\FtpPublisher;
use Fastsite\Publish\Service\FtpTarget;
use Fastsite\Publish\Service\FtpTransport;
use Fastsite\Publish\Service\SecretScanner;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Publishing to the customer's OWN web host.
 *
 * The load-bearing test here is the destructive one: that directory routinely
 * already holds something the customer cares about, so the publisher must
 * delete only what it uploaded itself — never "everything that is not in this
 * build". Everything else (incrementality, retry-after-failure, retargeting)
 * exists to keep that promise true across runs.
 */
final class FtpPublishTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private SiteRepository $siteRepo;
    private ContentService $content;
    private BuildService $build;
    private FakeFtpTransport $transport;
    private FtpPublisher $publisher;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-ftp-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $cipher = new TokenCipher(str_repeat('k', 32));
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot, $cipher);
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), $appRoot);
        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            $componentRenderer,
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)),
            new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"),
        );

        $this->transport = new FakeFtpTransport();
        $this->publisher = new FtpPublisher(
            $this->databases,
            $this->siteRepo,
            $this->transport,
            new SecretScanner(new NullLogger()),
            $cipher,
            $clock,
            new NullLogger(),
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

    private function seed(): Site
    {
        $site = $this->sites->create('ftpsite', 'FTP Site', 'https://ftp.example.com');
        $this->content->create($site, [
            'type' => 'post', 'title' => 'Hello world', 'excerpt' => 'An intro.',
            'body_html' => '<p>Body.</p>', 'status' => 'published',
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'About', 'body_html' => '<p>About us.</p>', 'status' => 'published',
        ]);

        $site = $this->sites->update('ftpsite', [
            'ftp_host' => 'files.customer.example',
            'ftp_username' => 'deploy',
            'ftp_password' => 'hunter2',
            'ftp_remote_path' => '/public_html',
            'ftp_protocol' => 'sftp',
            'serve_mode' => 'ftp',
        ]);
        $this->build->build($site);

        return $this->reload();
    }

    private function reload(): Site
    {
        return $this->siteRepo->findByHandle('ftpsite') ?? throw new \RuntimeException('site vanished');
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['remote_path'],
            $this->databases->site('ftpsite')->fetchAllAssociative('SELECT remote_path FROM ftp_manifest ORDER BY remote_path'),
        );
    }

    public function testFirstPublishUploadsTheWholeBuildAndSecondUploadsNothing(): void
    {
        $site = $this->seed();

        $first = $this->publisher->publish($site);
        self::assertGreaterThan(0, $first['uploaded']);
        self::assertSame(0, $first['skipped']);
        self::assertContains('index.html', $this->transport->uploaded);
        // The generated .htaccess is a dotfile; the enumeration must carry it.
        self::assertContains('.htaccess', $this->transport->uploaded, 'The .htaccess must reach the host, or the site loses its headers and redirects.');

        $this->transport->reset();

        $second = $this->publisher->publish($this->reload());
        self::assertSame(0, $second['uploaded'], 'An unchanged build must not re-upload a single file.');
        self::assertSame($first['uploaded'], $second['skipped']);
        self::assertSame([], $this->transport->uploaded);
        self::assertSame([], $this->transport->deleted);
    }

    public function testAFileWeNeverUploadedIsNeverDeleted(): void
    {
        $site = $this->seed();
        $this->publisher->publish($site);

        // The customer's existing site, sitting in the same directory.
        $this->transport->remote['webmail/index.php'] = 'not ours';
        $this->transport->reset();

        $this->publisher->publish($this->reload());

        self::assertSame([], $this->transport->deleted, 'Nothing outside our manifest may ever be deleted.');
        self::assertArrayHasKey('webmail/index.php', $this->transport->remote);
    }

    public function testOurOwnOrphanIsRemovedWhenItLeavesTheBuild(): void
    {
        $site = $this->seed();
        $this->publisher->publish($site);

        $rows = $this->databases->site('ftpsite')->fetchAllAssociative(
            "SELECT id FROM content WHERE type = 'page' LIMIT 1",
        );
        $this->content->softDelete($site, (int) $rows[0]["id"]);
        $this->build->build($this->reload());

        $this->transport->reset();
        $result = $this->publisher->publish($this->reload());

        self::assertGreaterThan(0, $result['deleted']);
        self::assertNotEmpty($this->transport->deleted);
        foreach ($this->transport->deleted as $path) {
            self::assertArrayNotHasKey($path, $this->transport->remote);
            self::assertNotContains($path, $this->manifestPaths(), 'A deleted orphan must drop out of the manifest.');
        }
    }

    /**
     * A failed transfer must not be recorded as uploaded — otherwise the retry
     * sees a matching hash and "skips" a file that never arrived.
     */
    public function testAFailedUploadIsNotRecordedAndIsRetriedNextPublish(): void
    {
        $site = $this->seed();
        $this->transport->failOn = 'index.html';

        try {
            $this->publisher->publish($site);
            self::fail('A failed upload must surface, not pass silently.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNotContains('index.html', $this->manifestPaths());

        $this->transport->failOn = null;
        $this->transport->reset();
        $this->publisher->publish($this->reload());

        self::assertContains('index.html', $this->transport->uploaded, 'The file that failed must be re-uploaded, not skipped.');
    }

    /**
     * A changed destination means the manifest describes a server we no longer
     * talk to, so deleting "orphans" there would delete paths that were never
     * ours on that machine.
     */
    public function testRetargetingResetsTheManifestAndDeletesNothing(): void
    {
        $site = $this->seed();
        $this->publisher->publish($site);
        self::assertNotSame('', $this->reload()->ftpTargetKey);

        $moved = $this->sites->update('ftpsite', ['ftp_host' => 'newhost.customer.example']);
        $this->transport->reset();

        $result = $this->publisher->publish($moved);

        self::assertSame(0, $result['deleted'], 'A fresh host must never be asked to delete anything.');
        self::assertSame(0, $result['skipped'], 'Nothing on a new host can be assumed already uploaded.');
        self::assertGreaterThan(0, $result['uploaded']);
        self::assertSame([], $this->transport->deleted);
    }

    /** Rotating a password is not a new destination and must not force a full re-upload. */
    public function testChangingOnlyThePasswordKeepsTheManifest(): void
    {
        $site = $this->seed();
        $first = $this->publisher->publish($site);

        $rotated = $this->sites->update('ftpsite', ['ftp_password' => 'new-password']);
        $this->transport->reset();

        $second = $this->publisher->publish($rotated);
        self::assertSame(0, $second['uploaded']);
        self::assertSame($first['uploaded'], $second['skipped']);
    }

    public function testPublishRefusesWhenNoHostIsConfigured(): void
    {
        $site = $this->sites->create('barehost', 'Bare', 'https://bare.example.com');
        $this->content->create($site, ['type' => 'page', 'title' => 'Home', 'body_html' => '<p>Hi.</p>', 'status' => 'published']);
        $this->build->build($site);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no FTP host/i');
        $this->publisher->publish($site);
    }

    /** The decrypted password must reach the transport, not the ciphertext. */
    public function testStoredCredentialsAreDecryptedForTheTransport(): void
    {
        $this->publisher->publish($this->seed());

        self::assertInstanceOf(FtpTarget::class, $this->transport->target);
        self::assertSame('hunter2', $this->transport->target->password);
        self::assertSame('/public_html', $this->transport->target->remotePath);
        self::assertSame(22, $this->transport->target->effectivePort(), 'sftp with port 0 must resolve to 22.');
    }
}

/**
 * Records what a real host would have been asked to do. Same shape as the
 * anonymous CfTransport fake in CloudflarePagesServiceTest — public arrays,
 * no mocking framework.
 */
final class FakeFtpTransport implements FtpTransport
{
    public ?FtpTarget $target = null;
    /** @var array<string, string> remote path => contents */
    public array $remote = [];
    /** @var list<string> */
    public array $uploaded = [];
    /** @var list<string> */
    public array $deleted = [];
    /** Upload of this path throws, simulating a mid-run transfer failure. */
    public ?string $failOn = null;

    public function open(FtpTarget $target): void
    {
        $this->target = $target;
    }

    public function upload(string $remoteRelative, string $localFile): void
    {
        if ($this->failOn !== null && $remoteRelative === $this->failOn) {
            throw new \RuntimeException('simulated transfer failure on ' . $remoteRelative);
        }
        $this->remote[$remoteRelative] = (string) file_get_contents($localFile);
        $this->uploaded[] = $remoteRelative;
    }

    public function delete(string $remoteRelative): void
    {
        unset($this->remote[$remoteRelative]);
        $this->deleted[] = $remoteRelative;
    }

    public function probe(): array
    {
        return ['ok' => true, 'note' => 'fake transport'];
    }

    public function close(): void
    {
    }

    /** Clears the call log while KEEPING the remote state, as a real host would. */
    public function reset(): void
    {
        $this->uploaded = [];
        $this->deleted = [];
    }
}
