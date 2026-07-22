<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Serve\DirectServeGate;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\RedirectService;
use Fastsite\Site\Service\SiteService;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Direct serving: DirectServeGate matches a site by Host header and serves
 * its build/ tree — headers from the site's own _headers, redirects from the
 * redirects table, a miss = the site's 404.html + a missing_paths row. On the
 * engine's own host, or for a git-mode site, the gate must stand aside.
 */
final class DirectServeTest extends TestCase
{
    private const APP_URL = 'https://engine.example';

    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private RedirectService $redirects;
    private DirectServeGate $gate;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-serve-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $repo = new DbalSiteRepository($this->databases, $clock);
        $this->sites = new SiteService($repo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->redirects = new RedirectService($this->databases, $clock);
        $this->gate = new DirectServeGate($repo, $this->databases, $this->redirects, self::APP_URL);

        $this->sites->create('dsite', 'Direct Site', 'https://example.test');
        $this->sites->update('dsite', ['serve_mode' => 'direct']);

        $build = $this->databases->sitePath('dsite') . '/build';
        mkdir($build . '/blog/hello', 0770, true);
        mkdir($build . '/assets', 0770, true);
        file_put_contents($build . '/index.html', '<!doctype html><title>Home</title>Direct home');
        file_put_contents($build . '/blog/hello/index.html', '<!doctype html><title>Hello</title>Post body');
        file_put_contents($build . '/assets/site.css', 'body{margin:0}');
        file_put_contents($build . '/404.html', '<!doctype html><title>404</title>Custom not found');
        file_put_contents($build . '/_headers', <<<TXT
            /*
              X-Content-Type-Options: nosniff
              Content-Security-Policy: script-src 'none'; frame-ancestors 'self'
            /assets/*
              Cache-Control: public, max-age=31536000, immutable

            TXT);
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

    private function get(string $url, array $headers = [], string $method = 'GET'): ?ResponseInterface
    {
        return $this->gate->intercept(new ServerRequest($method, $url, $headers));
    }

    public function testServesIndexWithHeadersFromTheSitesOwnHeadersFile(): void
    {
        $response = $this->get('https://example.test/');
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Direct home', (string) $response->getBody());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString("script-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testAssetGetsImmutableCachingAndHtmlDoesNot(): void
    {
        $asset = $this->get('https://example.test/assets/site.css');
        self::assertNotNull($asset);
        self::assertSame(200, $asset->getStatusCode());
        self::assertStringContainsString('immutable', $asset->getHeaderLine('Cache-Control'));
        self::assertSame('text/css', $asset->getHeaderLine('Content-Type'));

        $html = $this->get('https://example.test/blog/hello/');
        self::assertNotNull($html);
        self::assertStringNotContainsString('immutable', $html->getHeaderLine('Cache-Control'));
    }

    public function testConditionalGetReturns304(): void
    {
        $first = $this->get('https://example.test/');
        self::assertNotNull($first);
        $lastModified = $first->getHeaderLine('Last-Modified');
        self::assertNotSame('', $lastModified);

        $second = $this->get('https://example.test/', ['If-Modified-Since' => $lastModified]);
        self::assertNotNull($second);
        self::assertSame(304, $second->getStatusCode());
    }

    public function testSlashlessDirectoryUrlRedirectsToCanonicalForm(): void
    {
        $response = $this->get('https://example.test/blog/hello');
        self::assertNotNull($response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/blog/hello/', $response->getHeaderLine('Location'));
    }

    public function testWwwRedirectsToApex(): void
    {
        $response = $this->get('https://www.example.test/blog/hello/?utm=x');
        self::assertNotNull($response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://example.test/blog/hello/?utm=x', $response->getHeaderLine('Location'));
    }

    public function testRedirectsTableIsHonored(): void
    {
        $this->redirects->save('dsite', '/old-post', '/blog/hello/', 301);

        $response = $this->get('https://example.test/old-post');
        self::assertNotNull($response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/blog/hello/', $response->getHeaderLine('Location'));
    }

    public function testMissServesSite404AndRecordsThePath(): void
    {
        $response = $this->get('https://example.test/nope');
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Custom not found', (string) $response->getBody());

        $missing = array_column($this->redirects->listMissing('dsite'), 'path');
        self::assertContains('/nope', $missing);
    }

    public function testTraversalIsContained(): void
    {
        // Outside the build root — must be a site 404, never a file leak.
        $secret = $this->databases->sitePath('dsite') . '/site.db';
        self::assertFileExists($secret);

        $response = $this->get('https://example.test/' . rawurlencode('../site.db'));
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('SQLite', (string) $response->getBody());
    }

    public function testNonGetIs405(): void
    {
        $response = $this->get('https://example.test/', [], 'POST');
        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testHeadReturnsHeadersWithoutBody(): void
    {
        $response = $this->get('https://example.test/', [], 'HEAD');
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testGitModeSiteAndEngineHostFallThrough(): void
    {
        $this->sites->create('gsite', 'Git Site', 'https://gitmode.test');

        // serve_mode defaults to git — the gate must not intercept.
        self::assertNull($this->get('https://gitmode.test/'));
        // The engine's own host is never a site.
        self::assertNull($this->get(self::APP_URL . '/login'));
        // Unknown hosts fall through to normal routing.
        self::assertNull($this->get('https://stranger.test/'));
    }

    public function testDirectModeRequiresABaseUrl(): void
    {
        $this->sites->create('nourl', 'No URL');

        $this->expectException(\InvalidArgumentException::class);
        $this->sites->update('nourl', ['serve_mode' => 'direct']);
    }
}
