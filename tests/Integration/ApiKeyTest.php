<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class ApiKeyTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private ApiKeyService $keys;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — API key tests skipped.');
        }
        $this->redis->flushdb();

        $this->dataPath = sys_get_temp_dir() . '/fastsite-key-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $this->userId = (new UserService($this->databases, $clock))
            ->createUser('key-test@example.com', 'long-enough-password', 'Key Tester', 'user');
        $this->keys = new ApiKeyService($this->databases, $this->redis, $clock);
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
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataPath);
        }
    }

    public function testCreateVerifyScopesAndSites(): void
    {
        $key = $this->keys->create($this->userId, 'test', ['content:write', 'publish'], ['demo']);
        self::assertMatchesRegularExpression('/^fs_[A-Za-z0-9]{8}_[A-Za-z0-9]{32}$/', $key['token']);

        $access = $this->keys->verify($key['token']);
        self::assertNotNull($access);
        self::assertTrue($access->hasScope('content:write'));
        self::assertFalse($access->hasScope('backup:restore'));
        self::assertTrue($access->allowsSite('demo'));
        self::assertFalse($access->allowsSite('other'));

        // Second verify comes from the Redis cache and must agree.
        $cached = $this->keys->verify($key['token']);
        self::assertNotNull($cached);
        self::assertSame($access->scopes, $cached->scopes);
    }

    public function testVerifyRejectsGarbageAndWrongSecret(): void
    {
        self::assertNull($this->keys->verify('not-a-token'));
        self::assertNull($this->keys->verify('fs_AAAAAAAA_' . str_repeat('B', 32)));

        $key = $this->keys->create($this->userId, 'test', ['content:read']);
        $tampered = substr($key['token'], 0, -1) . ($key['token'][-1] === 'a' ? 'b' : 'a');
        self::assertNull($this->keys->verify($tampered));
    }

    public function testRevokeIsImmediateDespiteCache(): void
    {
        $key = $this->keys->create($this->userId, 'test', ['content:read']);
        self::assertNotNull($this->keys->verify($key['token'])); // warms the cache

        $this->keys->revoke($key['id']);

        self::assertNull($this->keys->verify($key['token']));
    }

    public function testRotateInvalidatesOldToken(): void
    {
        $old = $this->keys->create($this->userId, 'test', ['publish'], ['demo']);
        self::assertNotNull($this->keys->verify($old['token']));

        $new = $this->keys->rotate($old['id']);

        self::assertNull($this->keys->verify($old['token']));
        $access = $this->keys->verify($new['token']);
        self::assertNotNull($access);
        self::assertTrue($access->hasScope('publish'));
        self::assertSame(['demo'], $access->sites);
    }

    public function testAdminScopeImpliesEverything(): void
    {
        $key = $this->keys->create($this->userId, 'root', ['admin']);
        $access = $this->keys->verify($key['token']);
        self::assertNotNull($access);
        self::assertTrue($access->hasScope('backup:restore'));
        self::assertTrue($access->hasScope('content:write'));
    }

    public function testAlreadyExpiredKeyIsRejected(): void
    {
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() - 5);
        $key = $this->keys->create($this->userId, 'expired', ['content:read'], ['*'], $expires);

        self::assertNull($this->keys->verify($key['token']));
    }

    public function testCacheTtlIsCappedAtKeyExpiry(): void
    {
        // A cached positive must never outlive the key — its Redis TTL is
        // bounded by the key's remaining lifetime (deterministic, no sleep).
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 100);
        $key = $this->keys->create($this->userId, 'shortlived', ['content:read'], ['*'], $expires);

        self::assertNotNull($this->keys->verify($key['token'])); // warms cache

        $ttl = (int) $this->redis->ttl('fastsite:akv:' . hash('sha256', $key['token']));
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(100, $ttl);
    }

    public function testUserInvalidationDropsWarmCaches(): void
    {
        $key = $this->keys->create($this->userId, 'test', ['content:read']);
        self::assertNotNull($this->keys->verify($key['token'])); // warms cache

        // Simulate a deactivation/soft-delete: flip the user AND bump gen.
        $this->databases->engine()->update('users', ['is_active' => 0], ['id' => $this->userId]);
        $this->keys->invalidateUser($this->userId);

        self::assertNull($this->keys->verify($key['token']));
    }

    public function testBruteForceThrottleStopsHittingArgon2id(): void
    {
        $key = $this->keys->create($this->userId, 'target', ['content:read']);
        [, $keyId] = explode('_', $key['token']);

        // Hammer the live key_id with wrong secrets.
        for ($i = 0; $i < 12; ++$i) {
            $this->keys->verify('fs_' . $keyId . '_' . str_repeat('z', 32));
        }

        // Even the CORRECT token is now refused until the window resets —
        // proof the throttle short-circuits before password_verify.
        self::assertNull($this->keys->verify($key['token']));
        self::assertGreaterThanOrEqual(10, (int) $this->redis->get('fastsite:akf:' . $keyId));
    }

    public function testUnknownScopeRejectedAtCreation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->keys->create($this->userId, 'bad', ['world:domination']);
    }
}
