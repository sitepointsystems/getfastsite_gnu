<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Identity\Service\LoginFlow;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\TotpService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * M11 auth stack: TOTP round-trip, the two-step login flow, remember-me
 * rotation + theft detection, CSRF tokens. Real Redis (db 15).
 */
final class DashboardAuthTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private SessionService $sessions;
    private TotpService $totp;
    private RememberTokenService $remember;
    private LoginFlow $flow;
    private UserService $users;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — dashboard auth tests skipped.');
        }
        $this->redis->flushdb();

        $this->dataPath = sys_get_temp_dir() . '/fastsite-dash-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $cipher = new TokenCipher(str_repeat('s', 32));
        $this->sessions = new SessionService($this->databases, $this->redis, $clock);
        $this->totp = new TotpService($this->databases, $cipher, $clock, $this->redis);
        $this->remember = new RememberTokenService($this->databases, $clock);
        $this->flow = new LoginFlow($this->sessions, $this->totp, $this->redis);
        $this->users = new UserService($this->databases, $clock);

        $this->userId = $this->users->createUser('dash@example.com', 'a-strong-password', 'Dash', 'admin');
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

    public function testLoginWithoutTotpMintsSessionDirectly(): void
    {
        $result = $this->flow->begin('dash@example.com', 'a-strong-password', '1.2.3.4', 'phpunit');

        self::assertSame('ok', $result['status']);
        $user = $this->sessions->resolve($result['session']);
        self::assertNotNull($user);
        self::assertSame('dash@example.com', $user['email']);
    }

    public function testBadPasswordFails(): void
    {
        self::assertSame('bad', $this->flow->begin('dash@example.com', 'wrong', '1.2.3.4', 'x')['status']);
    }

    public function testTotpEnableThenLoginRequiresCode(): void
    {
        $seed = $this->totp->generateSeed();
        self::assertTrue($this->totp->enable($this->userId, $seed, self::codeFor($seed)));
        self::assertTrue($this->totp->isEnabled($this->userId));

        $result = $this->flow->begin('dash@example.com', 'a-strong-password', '1.2.3.4', 'phpunit');
        self::assertSame('totp', $result['status']);

        // Wrong code keeps the challenge alive but returns null.
        self::assertNull($this->flow->completeTotp($result['challenge'], '000000', '1.2.3.4', 'phpunit'));

        // The replay guard burned the enable step's window; wait for a
        // FRESH step or use drift: enable() consumed the current step, so
        // compute the code for the NEXT time step (verify allows ±1 drift).
        $session = $this->flow->completeTotp($result['challenge'], self::codeFor($seed, 1), '1.2.3.4', 'phpunit');
        self::assertNotNull($session);
        self::assertNotNull($this->sessions->resolve($session));

        // The challenge is single-success: reusing it fails.
        self::assertNull($this->flow->completeTotp($result['challenge'], self::codeFor($seed, -1), '1.2.3.4', 'phpunit'));
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        $seed = $this->totp->generateSeed();
        $code = self::codeFor($seed);

        self::assertTrue($this->totp->verify($seed, $code, 999));
        self::assertFalse($this->totp->verify($seed, $code, 999), 'Same code in the same window must be rejected.');
    }

    public function testRememberTokenRotatesAndDetectsTheft(): void
    {
        $cookie = $this->remember->issue($this->userId);

        $first = $this->remember->consume($cookie);
        self::assertNotNull($first);
        [$uid, $fresh] = $first;
        self::assertSame($this->userId, $uid);
        self::assertNotSame($cookie, $fresh);

        // Replaying the consumed cookie: selector is gone → plain null.
        self::assertNull($this->remember->consume($cookie));

        // Theft simulation: correct selector, wrong validator → every
        // remember token for the user is revoked, including the fresh one.
        [$selector] = explode('.', $fresh);
        self::assertNull($this->remember->consume($selector . '.' . str_repeat('ab', 32)));
        self::assertNull($this->remember->consume($fresh), 'Theft signal must revoke the whole set.');
    }

    public function testCsrfTokenIsSessionBoundAndStable(): void
    {
        $session = $this->sessions->mintSession($this->userId, '1.2.3.4', 'phpunit');

        $token = $this->sessions->csrfToken($session);
        self::assertSame($token, $this->sessions->csrfToken($session));
        self::assertTrue($this->sessions->verifyCsrf($session, $token));
        self::assertFalse($this->sessions->verifyCsrf($session, 'forged'));

        $other = $this->sessions->mintSession($this->userId, '1.2.3.4', 'phpunit');
        self::assertFalse($this->sessions->verifyCsrf($other, $token), 'Token must not transfer between sessions.');
    }

    public function testPasswordChangeRequiresCurrentAndKillsOtherSessions(): void
    {
        $keep = $this->sessions->mintSession($this->userId, '1.1.1.1', 'keep');
        $other = $this->sessions->mintSession($this->userId, '2.2.2.2', 'other');

        self::assertFalse($this->users->changePassword($this->userId, 'wrong-current', 'a-brand-new-password'));
        self::assertTrue($this->users->changePassword($this->userId, 'a-strong-password', 'a-brand-new-password'));

        $this->sessions->logoutAllForUser($this->userId, $keep);
        self::assertNotNull($this->sessions->resolve($keep));
        self::assertNull($this->sessions->resolve($other));

        self::assertSame('ok', $this->flow->begin('dash@example.com', 'a-brand-new-password', '1.2.3.4', 'x')['status']);
    }

    public function testTotpBruteForceCappedAcrossFreshChallenges(): void
    {
        $seed = $this->totp->generateSeed();
        self::assertTrue($this->totp->enable($this->userId, $seed, self::codeFor($seed)));

        // Attacker knows the password; loops fresh challenges guessing codes.
        // After USER_2FA_MAX_FAILS (10) wrong guesses the account is locked
        // even though each challenge only saw a few tries.
        $begins = 0;
        for ($i = 0; $i < 12; ++$i) {
            $r = $this->flow->begin('dash@example.com', 'a-strong-password', '9.9.9.9', 'atk');
            if ($r['status'] !== 'totp') {
                break; // locked out — begin() now refuses a new challenge
            }
            ++$begins;
            $this->flow->completeTotp($r['challenge'], '000000', '9.9.9.9', 'atk');
        }

        self::assertLessThanOrEqual(11, $begins, 'Fresh challenges must not reset the 2FA guess budget.');
        self::assertSame('bad', $this->flow->begin('dash@example.com', 'a-strong-password', '9.9.9.9', 'atk')['status']);
    }

    public function testLoginMissRunsTheKdfSoTimingCannotEnumerate(): void
    {
        // The dummy hash on the not-found path must be a REAL argon2id hash
        // that actually runs, not a malformed string that short-circuits.
        $missStart = hrtime(true);
        self::assertNull($this->sessions->verifyCredentials('nobody@example.com', 'whatever-password', '3.3.3.3'));
        $missMs = (hrtime(true) - $missStart) / 1e6;

        // A real argon2id verify at 64MiB/4/2 is many milliseconds; a
        // short-circuited malformed-hash miss returns in microseconds.
        self::assertGreaterThan(5.0, $missMs, 'User-not-found path must run the KDF (no timing oracle).');
    }

    /** RFC 6238 reference implementation for test-side code generation. */
    private static function codeFor(string $b32Seed, int $stepOffset = 0): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $key = '';
        $bits = 0;
        $value = 0;
        foreach (str_split(strtoupper($b32Seed)) as $char) {
            $value = ($value << 5) | (int) strpos($alphabet, $char);
            $bits += 5;
            if ($bits >= 8) {
                $key .= \chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        $counter = intdiv(time(), 30) + $stepOffset;
        $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $code = (((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3])) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }
}
