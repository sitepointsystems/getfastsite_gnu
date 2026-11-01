<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Handler\PreviewServeHandler;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\BuildHeaders;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Preview must answer with the build's own `_headers`, or it is not a test
 * surface: a shell that violates the scripts-mode CSP (Alpine compiling its
 * `x-*` expressions through `new Function()`, an embed that evals) would work
 * in preview and break on the CDN, silently.
 *
 * Preview's own no-store/noindex still win over the build's immutable caching
 * — a preview pinned in the browser across rebuilds is its own bug.
 */
final class PreviewServeTest extends TestCase
{
    private const CSP = "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: blob:; frame-ancestors 'self'";

    private string $dataPath;
    private SiteDatabaseManager $databases;
    private PreviewServeHandler $handler;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-preview-' . bin2hex(random_bytes(6));
        mkdir($this->dataPath, 0770, true);

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $repo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($repo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $sites->create('psite', 'Preview Site', 'https://preview.test');

        // Dash only needs its site repository here: siteFor() → sitesFor() →
        // scopeToUser(), and an admin short-circuits before touching the DB.
        $dash = (new \ReflectionClass(Dash::class))->newInstanceWithoutConstructor();
        $this->setPrivate($dash, 'sites', $repo);

        // BuildService is deliberately absent — a fresh stamp means no rebuild,
        // and this test is about the serving path, not the build.
        $this->handler = (new \ReflectionClass(PreviewServeHandler::class))->newInstanceWithoutConstructor();
        $this->setPrivate($this->handler, 'dash', $dash);
        $this->setPrivate($this->handler, 'databases', $this->databases);
        $this->setPrivate($this->handler, 'buildHeaders', new BuildHeaders());
        // The staleness signature reads component hooks AND the components
        // themselves (bundle hashes + verbatim flags) from the site db.
        $this->setPrivate($this->handler, 'componentHooks', new \Fastsite\Component\Service\ComponentHookService(
            $this->databases,
            $clock,
            new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock),
        ));
        $this->setPrivate($this->handler, 'components', new \Fastsite\Component\Service\ComponentService(
            new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock),
            $this->databases,
            new NullLogger(),
            $appRoot,
        ));
        // …and form definitions, for the same reason: a form's markup is
        // generated at build, so editing one changes built pages with no
        // content timestamp moving.
        $this->setPrivate($this->handler, 'forms', new \Fastsite\Form\Service\FormService($this->databases, $clock));
        // …and site scripts. This was missing, and the signature's try/catch
        // around the scripts hash swallowed the uninitialized-property error —
        // so the scripts fragment was silently absent from every assertion in
        // this file. The catch is gone (the service owns its own), which is
        // what surfaced it.
        $this->setPrivate($this->handler, 'scripts', new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock));

        $this->writePreviewTree();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dataPath);
    }

    public function testHtmlCarriesTheBuildsCsp(): void
    {
        $response = $this->get('');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Preview home', (string) $response->getBody());
        self::assertSame(self::CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAssetCarriesTheCspToo(): void
    {
        $response = $this->get('assets/app.css');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
    }

    /**
     * `_headers` marks /assets/* immutable for a year. Preview must not: the
     * same URL holds different bytes after the next build.
     */
    public function testPreviewCachingOverridesTheBuildsImmutableRule(): void
    {
        $response = $this->get('assets/app.css');

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
    }

    /** A tree built before this contract existed simply has no extra headers. */
    public function testMissingHeadersFileDegradesToPlainServing(): void
    {
        unlink($this->databases->sitePath('psite') . '/preview/_headers');

        $response = $this->get('');

        self::assertSame(200, $response->getStatusCode());
        // Assert the real page, not the rebuild modal — which is also a 200
        // with no CSP, and would let this pass for the wrong reason.
        self::assertStringContainsString('Preview home', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The Content screen's per-row Preview button deep-links straight at one
     * page. A fresh tree serves it directly — no bounce through the root.
     */
    public function testDeepLinkedPageIsServedFromAFreshTree(): void
    {
        $response = $this->get('blog/hello/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello post', (string) $response->getBody());
        self::assertSame(self::CSP, $response->getHeaderLine('Content-Security-Policy'));
    }

    /**
     * …and a STALE tree rebuilds first, returning to the deep-linked page
     * rather than the site root. A per-page Preview that silently serves the
     * build from before the edit you just made is worse than no preview: the
     * staleness gate used to run only on the root, so every deep link was
     * served as-is.
     */
    public function testDeepLinkedPageRebuildsAndComesBackToItself(): void
    {
        file_put_contents($this->databases->sitePath('psite') . '/preview/.stamp', 'stale-signature');

        $response = $this->get('blog/hello/');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Rebuilding preview', $body);
        self::assertStringContainsString('/sites/psite/preview/blog/hello/?rebuild=1', $body);
        self::assertStringNotContainsString('url=/sites/psite/preview/?rebuild=1', $body);
    }

    /** Sub-resources of an already-current page skip the gate entirely. */
    public function testAssetsAreServedEvenWhenTheTreeIsStale(): void
    {
        file_put_contents($this->databases->sitePath('psite') . '/preview/.stamp', 'stale-signature');

        $response = $this->get('assets/app.css');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('body{color:#000}', (string) $response->getBody());
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequest('GET', 'https://engine.test/sites/psite/preview/' . $path))
            ->withAttribute('site', 'psite')
            ->withAttribute('path', $path)
            ->withAttribute('user', ['id' => 1, 'email' => 'a@b.test', 'name' => 'A', 'role' => 'admin']);

        return $this->handler->handle($request);
    }

    private function writePreviewTree(): void
    {
        $preview = $this->databases->sitePath('psite') . '/preview';
        mkdir($preview . '/assets', 0770, true);
        mkdir($preview . '/blog/hello', 0770, true);
        file_put_contents($preview . '/index.html', '<!doctype html><title>x</title>Preview home');
        file_put_contents($preview . '/blog/hello/index.html', '<!doctype html><title>x</title>Hello post');
        file_put_contents($preview . '/assets/app.css', 'body{color:#000}');
        file_put_contents($preview . '/_headers', <<<TXT
            /*
              X-Content-Type-Options: nosniff
              Referrer-Policy: strict-origin-when-cross-origin
              Content-Security-Policy: {$this->csp()}
            /assets/*
              Cache-Control: public, max-age=31536000, immutable

            TXT);

        // A stamp matching the handler's own signature = "fresh", so the request
        // serves the tree instead of returning the rebuild modal.
        $site = (new DbalSiteRepository($this->databases, new SystemClock()))->findByHandle('psite');
        $signature = (new \ReflectionMethod(PreviewServeHandler::class, 'signature'))
            ->invoke($this->handler, $site);
        file_put_contents($preview . '/.stamp', $signature);
    }

    private function csp(): string
    {
        return self::CSP;
    }

    /**
     * Media is a build input that moves no content timestamp: replacing an
     * image re-renders every page that embeds it (new URL, new bytes) while
     * every content row, the theme version and the site row stay put. Preview
     * is where someone goes to CHECK a replace worked, so a signature blind to
     * media would serve them the pre-replace tree — the documented staleness
     * symptom, third time around.
     */
    public function testStalenessSignatureNoticesMediaChanges(): void
    {
        $site = (new DbalSiteRepository($this->databases, new SystemClock()))->findByHandle('psite');
        self::assertNotNull($site);

        $signature = function () use ($site): string {
            $m = new \ReflectionMethod(PreviewServeHandler::class, 'signature');

            return (string) $m->invoke($this->handler, $site);
        };

        $before = $signature();

        $db = $this->databases->site('psite');
        $db->insert('media', [
            'kind' => 'image', 'original_name' => 'logo.png', 'slug' => 'logo', 'ext' => 'png',
            'mime' => 'image/png', 'sha256' => str_repeat('a', 64), 'bytes' => 100,
            'alt' => 'Logo', 'decorative' => 0, 'storage' => 'git',
            'disk_path' => 'media/originals/2026/08/1-logo.png', 'status' => 'ready',
            'created_at' => '2026-08-31T00:00:00Z', 'updated_at' => '2026-08-31T00:00:00Z',
        ]);
        $afterUpload = $signature();
        self::assertNotSame($before, $afterUpload, 'A new image changes the build.');

        // The replace case: same row, same name, new content and revision.
        $db->executeStatement("UPDATE media SET revision = 1, sha256 = ?, updated_at = '2026-08-31T01:00:00Z' WHERE id = 1", [str_repeat('b', 64)]);
        self::assertNotSame($afterUpload, $signature(), 'A replaced image changes the build.');
    }

    private function setPrivate(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target::class, $property);
        $ref->setValue($target, $value);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
