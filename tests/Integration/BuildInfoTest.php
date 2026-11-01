<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Kernel\Route;
use Fastsite\Ops\BuildInfoService;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use PHPUnit\Framework\TestCase;

use function FastRoute\simpleDispatcher;

/**
 * Reporting what is actually deployed.
 *
 * Built after a shipped security fix (sharp 0.33.5 -> 0.35.5, for CVEs
 * inherited from libvips/libheif/librsvg) was reported as MISSING for an
 * afternoon. Two things caused that, and both are pinned here:
 *
 * 1. `/healthz` answers 200 from a fresh container and from an old one an
 *    orchestrator kept alive after a failed build, so it cannot answer "did
 *    the deploy land" — and nothing else reported a version at all.
 * 2. The version was then DEDUCED from encoder output, which does not work:
 *    AVIF bytes track the libvips thread count (aom parallelises by tile), so
 *    identical libraries encode differently on machines with different core
 *    counts. Hence a surface that REPORTS versions.
 *
 * The assertions deliberately name libvips, aom, libheif and librsvg: those
 * are the libraries advisories are written about, they live inside sharp's
 * prebuilt binary, and sharp's own version number says nothing about them.
 */
final class BuildInfoTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->tmp);
        $this->tmp = '';
    }

    public function testItReportsTheLibrariesAnAdvisoryIsActuallyAbout(): void
    {
        $info = (new BuildInfoService(\dirname(__DIR__, 2)))->info();

        if ($info['sharp'] === null) {
            self::markTestSkipped('sharp not installed here: ' . (string) $info['sharp_error']);
        }

        self::assertSame(PHP_VERSION, $info['php']);
        self::assertNotNull($info['node'], 'node version should be reported');
        self::assertNotNull($info['sharp']['version'], 'sharp carries its own version in .versions');

        // The whole point: the native libraries, not just the JS wrapper.
        foreach (['vips', 'aom', 'heif', 'rsvg'] as $library) {
            self::assertArrayHasKey(
                $library,
                $info['sharp']['libraries'],
                $library . ' must be reported — it is what an advisory names',
            );
            self::assertMatchesRegularExpression('/\d/', $info['sharp']['libraries'][$library]);
        }
    }

    public function testTheSummaryNamesSharpAndLibvipsForDoctorAndLogs(): void
    {
        $service = new BuildInfoService(\dirname(__DIR__, 2));
        if ($service->info()['sharp'] === null) {
            self::markTestSkipped('sharp not installed here');
        }

        $summary = $service->summary();

        self::assertStringContainsString('PHP ' . PHP_VERSION, $summary);
        self::assertStringContainsString('sharp ', $summary);
        self::assertStringContainsString('libvips ', $summary);
    }

    public function testAMissingBuildStampIsNotAnError(): void
    {
        $info = (new BuildInfoService($this->root()))->info();

        self::assertNull($info['app']['sha']);
        self::assertNull($info['app']['built_at']);
        self::assertSame(PHP_VERSION, $info['php'], 'the rest of the report still works');
    }

    public function testTheImageStampIsReadWhenPresent(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        file_put_contents($root . '/var/build.json', json_encode([
            'sha' => '1ee4e1bdeadbeefcafe',
            'built_at' => '2026-10-28T13:08:12Z',
            'flavor' => 'private',
        ], JSON_THROW_ON_ERROR));

        $info = (new BuildInfoService($root))->info();

        self::assertSame('1ee4e1bdeadbeefcafe', $info['app']['sha']);
        self::assertSame('2026-10-28T13:08:12Z', $info['app']['built_at']);
        self::assertSame('private', $info['app']['flavor']);
    }

    /**
     * A reporting surface that throws is worse than one that says "unknown":
     * it takes the endpoint down at the exact moment someone is diagnosing a
     * sick container.
     */
    public function testAMalformedImageStampIsIgnoredRatherThanFatal(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        file_put_contents($root . '/var/build.json', '{ this is not json');

        $info = (new BuildInfoService($root))->info();

        self::assertNull($info['app']['sha']);
        self::assertNull($info['app']['built_at']);
    }

    public function testAnUnreadableSharpReportsWhyInsteadOfThrowing(): void
    {
        // A root with no node_modules — the shape of a container where the
        // native install failed, which is precisely when this must still answer.
        $info = (new BuildInfoService($this->root()))->info();

        self::assertNull($info['sharp']);
        self::assertIsString($info['sharp_error']);
        self::assertNotSame('', $info['sharp_error']);
    }

    public function testTheRestRouteIsDeclaredBehindSitesRead(): void
    {
        $routes = require \dirname(__DIR__, 2) . '/config/routes.php';
        $dispatch = simpleDispatcher(static function (RouteCollector $r) use ($routes): void {
            foreach ($routes as $route) {
                \assert($route instanceof Route);
                $r->addRoute($route->method, $route->path, $route);
            }
        });

        $result = $dispatch->dispatch('GET', '/api/v1/build');

        self::assertSame(Dispatcher::FOUND, $result[0], '/api/v1/build did not resolve');
        /** @var Route $route */
        $route = $result[1];
        self::assertSame(\Fastsite\Api\V1\BuildInfoHandler::class, $route->handler);
        self::assertSame('sites:read', $route->scope);
        self::assertSame('api', $route->middlewareGroup, 'it must sit behind API-key auth');
    }

    /**
     * `/healthz` is the container healthcheck and carries NO auth, so the
     * build sha plus a full native library inventory must not land there: that
     * is a map of which CVEs to try, handed to anonymous callers.
     */
    public function testTheUnauthenticatedHealthRouteStillRevealsNothing(): void
    {
        $handler = new \Fastsite\Http\HealthHandler();
        $response = $handler->handle(new \GuzzleHttp\Psr7\ServerRequest('GET', '/healthz'));
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('vips', $body);
        self::assertStringNotContainsString('sha', $body);
        self::assertStringContainsString('"status":"ok"', $body);
    }

    /**
     * Read from the source rather than built: McpTools takes ~25 constructor
     * dependencies, and the thing worth pinning is that the tool is declared
     * at all and declares the least-privilege scope.
     */
    public function testTheMcpToolIsDeclaredBehindSitesRead(): void
    {
        $src = file_get_contents(\dirname(__DIR__, 2) . '/src/Mcp/McpTools.php');
        self::assertIsString($src);

        self::assertStringContainsString("\$this->tool('get_build_info'", $src);

        $at = strpos($src, "\$this->tool('get_build_info'");
        self::assertIsInt($at);
        $declaration = substr($src, $at, 2600);
        self::assertStringContainsString("'sites:read'", $declaration, 'get_build_info must require sites:read');
        self::assertStringContainsString('buildInfo->info()', $declaration);
    }

    private function root(): string
    {
        $this->tmp = sys_get_temp_dir() . '/fastsite-buildinfo-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);

        return $this->tmp;
    }
}
