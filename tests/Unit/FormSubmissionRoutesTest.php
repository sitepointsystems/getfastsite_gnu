<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Kernel\Route;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use PHPUnit\Framework\TestCase;

use function FastRoute\simpleDispatcher;

/**
 * The submission recovery routes sit under `/forms/`, in the same shape as
 * `/forms/{handle}` — so `submissions` can be read as a form handle.
 *
 * fast-route resolves by declaration order among same-shape variable routes,
 * which means the wrong order would not fail loudly: `POST
 * /forms/submissions/retry` would simply 404 as "unknown form: submissions",
 * and the recovery path would look broken for a reason nobody would guess
 * from the message. Hence a test on the dispatcher rather than a comment.
 */
final class FormSubmissionRoutesTest extends TestCase
{
    public function testSubmissionOpsAreNotSwallowedByTheFormHandleRoute(): void
    {
        $dispatch = $this->dispatcher();

        foreach (['retry', 'release', 'rescore', 'purge-spam'] as $op) {
            $result = $dispatch('POST', '/api/v1/sites/demo/forms/submissions/' . $op);

            self::assertSame(Dispatcher::FOUND, $result[0], $op . ' did not resolve');
            /** @var Route $route */
            $route = $result[1];
            self::assertSame(
                \Fastsite\Api\V1\FormSubmissionsActionHandler::class,
                $route->handler,
                $op . ' resolved to the wrong handler — check route declaration order',
            );
            self::assertSame($op, $result[2]['op'] ?? null);
        }
    }

    /** The ordinary form routes must still work, which is the other half. */
    public function testAnOrdinaryFormHandleStillResolves(): void
    {
        $result = $this->dispatcher()('PUT', '/api/v1/sites/demo/forms/contact');

        self::assertSame(Dispatcher::FOUND, $result[0]);
        /** @var Route $route */
        $route = $result[1];
        self::assertSame(\Fastsite\Api\V1\FormsHandler::class, $route->handler);
        self::assertSame('contact', $result[2]['handle'] ?? null);
    }

    /** A recovery route must demand the write scope, not merely read. */
    public function testRecoveryOpsRequireTheFormsWriteScope(): void
    {
        $result = $this->dispatcher()('POST', '/api/v1/sites/demo/forms/submissions/release');
        /** @var Route $route */
        $route = $result[1];

        self::assertSame('forms:write', $route->scope);
    }

    /** @return callable(string, string): array{0: int, 1?: mixed, 2?: array<string, string>} */
    private function dispatcher(): callable
    {
        /** @var list<Route> $routes */
        $routes = require \dirname(__DIR__, 2) . '/config/routes.php';

        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes): void {
            foreach ($routes as $route) {
                $r->addRoute($route->method, $route->path, $route);
            }
        });

        return static fn (string $method, string $path): array => $dispatcher->dispatch($method, $path);
    }
}
