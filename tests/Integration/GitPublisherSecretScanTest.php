<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Publish\Service\GitPublisher;
use Fastsite\Publish\Service\SecretScanner;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * M12 pre-push secret scan: the publisher must refuse to commit build output
 * that looks like a leaked secret (learned from a prior IP leak).
 */
final class GitPublisherSecretScanTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private GitPublisher $publisher;
    private Site $site;

    protected function setUp(): void
    {
        if (!self::hasGit()) {
            self::markTestSkipped('git not available.');
        }
        $this->dataPath = sys_get_temp_dir() . '/fastsite-pub-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        // A local-only site (no remote) — publish commits locally.
        $this->site = new Site(1, 'pubsite', 'Pub', 'pub.example.com', 'https://pub.example.com', 'en', [], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');

        $this->databases->provisionSite('pubsite');
        $this->publisher = new GitPublisher($this->databases, new NullLogger(), new SecretScanner(new NullLogger()), 'test', 'test@example.com');
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                \assert($item instanceof \SplFileInfo);
                // .git objects are read-only on Windows — force removal.
                @chmod($item->getPathname(), 0777);
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->dataPath);
        }
    }

    private function writeBuild(string $relative, string $content): void
    {
        $path = $this->databases->sitePath('pubsite') . '/build/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0770, true);
        }
        file_put_contents($path, $content);
    }

    public function testCleanBuildPublishes(): void
    {
        $this->writeBuild('index.html', '<!doctype html><title>Home</title><p>Nothing secret here.</p>');
        $result = $this->publisher->publish($this->site, 'publish: clean');
        self::assertTrue($result['committed']);
    }

    public function testPemPrivateKeyAbortsPublishEvenInContent(): void
    {
        // A PEM private key is unambiguous — it blocks anywhere, including
        // a content page (no legitimate page ships real key material).
        $this->writeBuild('index.html', "<title>ok</title><pre>-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1r\n-----END OPENSSH PRIVATE KEY-----</pre>");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/secret scan/i');
        $this->publisher->publish($this->site, 'publish: pem');
    }

    public function testFastsiteTokenInEngineArtifactAbortsPublish(): void
    {
        // A token-shaped string in an engine-generated deploy artifact is a
        // pipeline bug — block it.
        $this->writeBuild('index.html', '<title>ok</title>');
        $this->writeBuild('_headers', "/*\n  X-Debug: fs_AbCdEfGh_0123456789abcdef0123\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/secret scan/i');
        $this->publisher->publish($this->site, 'publish: artifact');
    }

    public function testEnvSecretAssignmentInArtifactAbortsPublish(): void
    {
        $this->writeBuild('index.html', '<title>ok</title>');
        $this->writeBuild('robots.txt', "User-agent: *\nAPP_SECRET=super-secret-value-that-should-never-ship\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/secret scan/i');
        $this->publisher->publish($this->site, 'publish: env');
    }

    public function testSecretShapedStringInContentPagePublishesAnyway(): void
    {
        // The core invariant: content must NEVER wedge the pipeline. A blog
        // post that mentions an env var name or a token-shaped string still
        // publishes (the scan logs a WARN but does not block).
        $this->writeBuild('blog/aws-guide/index.html', '<p>Set <code>APP_SECRET=changeme12345</code> and rotate keys like fs_AbCdEfGh_0123456789abcdef0123 regularly. Example: AKIAIOSFODNN7EXAMPLE.</p>');
        $result = $this->publisher->publish($this->site, 'publish: content mentions secrets');
        self::assertTrue($result['committed']);
    }

    private static function hasGit(): bool
    {
        $p = new Process(['git', '--version']);
        $p->run();

        return $p->isSuccessful();
    }
}
