<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Publish\Service\CfTransport;
use Fastsite\Publish\Service\CloudflarePagesService;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CloudflarePagesService against a canned transport: the happy path sends the
 * exact project/domain/DNS payloads, and the two realistic failure modes
 * (bad token, GitHub app not connected) come back as actionable step notes —
 * never as leaked exceptions.
 */
final class CloudflarePagesServiceTest extends TestCase
{
    private ?object $transport = null;

    private function service(callable $responder): CloudflarePagesService
    {
        $this->transport = new class($responder) implements CfTransport {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $calls = [];

            public function __construct(private readonly \Closure $responder)
            {
            }

            public function request(string $method, string $path, ?array $body, string $token): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

                return ($this->responder)($method, $path, $body);
            }
        };

        return new CloudflarePagesService($this->transport, new NullLogger());
    }

    /** @return list<array{method: string, path: string, body: ?array}> */
    private function calls(): array
    {
        return $this->transport?->calls ?? [];
    }

    private function site(string $repo = 'git@github.com:acme/site-dist.git'): Site
    {
        return new Site(
            id: 1, handle: 'acme', name: 'Acme', primaryDomain: 'acme.com',
            baseUrl: 'https://acme.com', primaryLocale: 'en', locales: [],
            postPathPattern: '/blog/{slug}/', pagePathPattern: '/{slug}/',
            githubRepo: $repo, githubBranch: 'main', mediaPublicBase: '',
            themeVersion: 1, status: 'active',
        );
    }

    private static function ok(array $result = []): array
    {
        return ['status' => 200, 'json' => ['success' => true, 'result' => $result, 'errors' => []]];
    }

    private static function err(string $message): array
    {
        return ['status' => 400, 'json' => ['success' => false, 'result' => null, 'errors' => [['code' => 1000, 'message' => $message]]]];
    }

    public function testHappyPathSendsProjectDomainAndDnsPayloads(): void
    {
        $service = $this->service(static function (string $method, string $path): array {
            return match (true) {
                $path === '/user/tokens/verify' => self::ok(['status' => 'active']),
                $path === '/accounts' => self::ok([['id' => 'acc-123', 'name' => 'Acme Cloud']]),
                str_ends_with($path, '/pages/projects') => self::ok(['name' => 'acme', 'subdomain' => 'acme.pages.dev']),
                str_contains($path, '/pages/projects/acme/domains') => self::ok(['name' => 'acme.com']),
                str_starts_with($path, '/zones?name=') => self::ok([['id' => 'zone-9']]),
                str_contains($path, '/dns_records') => self::ok(['id' => 'rec']),
                default => self::err('unexpected call: ' . $path),
            };
        });

        $result = $service->setup($this->site(), 'tok-abc');

        self::assertTrue($result['ok'], print_r($result['steps'], true));
        self::assertSame('acc-123', $result['account_id']);

        $project = null;
        $dns = [];
        foreach ($this->calls() as $call) {
            if (str_ends_with($call['path'], '/pages/projects') && $call['method'] === 'POST') {
                $project = $call['body'];
            }
            if (str_contains($call['path'], '/dns_records')) {
                $dns[] = $call['body'];
            }
        }

        self::assertNotNull($project, 'no project-create call was made');
        self::assertSame('acme', $project['name']);
        self::assertSame('main', $project['production_branch']);
        self::assertSame('github', $project['source']['type']);
        self::assertSame('acme', $project['source']['config']['owner']);
        self::assertSame('site-dist', $project['source']['config']['repo_name']);
        self::assertSame('', $project['build_config']['build_command']);

        self::assertCount(2, $dns, 'expected apex + www DNS records');
        self::assertSame(['acme.com', 'www.acme.com'], array_column($dns, 'name'));
        self::assertSame(['acme.pages.dev', 'acme.pages.dev'], array_column($dns, 'content'));
    }

    public function testInvalidTokenFailsWithGuidanceAndStops(): void
    {
        $service = $this->service(static fn (string $m, string $p): array => self::err('Invalid API Token'));

        $result = $service->setup($this->site(), 'bad');

        self::assertFalse($result['ok']);
        $last = end($result['steps']);
        self::assertSame('token', $last['step']);
        self::assertStringContainsString('Invalid API Token', $last['note']);
        self::assertStringContainsString('API Tokens', $last['note']); // tells them where to make one
        self::assertCount(1, $this->calls(), 'must stop after the failed verify');
    }

    public function testMissingGithubConnectionLinksTheConnectScreen(): void
    {
        $service = $this->service(static function (string $method, string $path): array {
            return match (true) {
                $path === '/user/tokens/verify' => self::ok(['status' => 'active']),
                $path === '/accounts' => self::ok([['id' => 'acc-123', 'name' => 'Acme Cloud']]),
                str_ends_with($path, '/pages/projects') => self::err('No GitHub installation found for this account'),
                default => self::err('unexpected call: ' . $path),
            };
        });

        $result = $service->setup($this->site('https://github.com/acme/site-dist'), 'tok');

        self::assertFalse($result['ok']);
        self::assertSame('acc-123', $result['account_id'], 'account survives so it can be cached');
        $last = end($result['steps']);
        self::assertSame('project', $last['step']);
        self::assertStringContainsString('pages/new/provider/github', $last['note']); // the exact fix link
    }

    public function testRepoParsingCoversSshAndHttpsShapes(): void
    {
        self::assertSame(['owner' => 'o', 'name' => 'r'], CloudflarePagesService::parseGithubRepo('git@github.com:o/r.git'));
        self::assertSame(['owner' => 'o', 'name' => 'r'], CloudflarePagesService::parseGithubRepo('https://github.com/o/r'));
        self::assertSame(['owner' => 'o', 'name' => 'r'], CloudflarePagesService::parseGithubRepo('https://github.com/o/r.git'));
        self::assertNull(CloudflarePagesService::parseGithubRepo(''));
        self::assertNull(CloudflarePagesService::parseGithubRepo('git@gitlab.com:o/r.git'));
    }

    public function testProjectNameIsSanitizedForPages(): void
    {
        self::assertSame('my-site', CloudflarePagesService::projectName('my_site'));
        self::assertSame('acme', CloudflarePagesService::projectName('acme'));
    }
}
