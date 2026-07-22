<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Access\OAuth\OAuthException;
use Fastsite\Access\OAuth\OAuthService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use PHPUnit\Framework\TestCase;

/**
 * OAuth 2.1 authorization server: DCR, PKCE, single-use codes, refresh
 * rotation + family revocation, token verification. No network/Redis.
 */
final class OAuthTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private OAuthService $oauth;
    private int $userId;

    protected function setUp(): void
    {
        $this->dataPath = sys_get_temp_dir() . '/fastsite-oauth-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $this->userId = (new UserService($this->databases, $clock))->createUser('oauth@example.com', 'a-long-password', 'OAuth User', 'admin');
        $this->oauth = new OAuthService($this->databases, $clock);
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

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function registerAndAuthorize(array $scopes = ['content:write', 'publish']): array
    {
        $client = $this->oauth->registerClient('Test', ['https://app.example.com/cb']);
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $this->oauth->issueCode($client['client_id'], $this->userId, $scopes, $challenge, 'https://app.example.com/cb');

        return [$client['client_id'], $code, $verifier, 'https://app.example.com/cb'];
    }

    public function testFullCodeExchangeWithPkce(): void
    {
        [$clientId, $code, $verifier, $redirect] = $this->registerAndAuthorize();

        $tokens = $this->oauth->exchangeCode($code, $verifier, $clientId, $redirect);
        self::assertStringStartsWith('fsoa_', $tokens['access_token']);
        self::assertStringStartsWith('fsor_', $tokens['refresh_token']);
        self::assertSame(3600, $tokens['expires_in']);

        $access = $this->oauth->verifyAccessToken($tokens['access_token']);
        self::assertNotNull($access);
        self::assertSame('oauth', $access->actorType);
        self::assertTrue($access->hasScope('publish'));
        self::assertFalse($access->hasScope('backup:restore'));
    }

    public function testWrongPkceVerifierRejected(): void
    {
        [$clientId, $code, , $redirect] = $this->registerAndAuthorize();

        $this->expectException(OAuthException::class);
        $this->oauth->exchangeCode($code, 'wrong-verifier', $clientId, $redirect);
    }

    public function testCodeIsSingleUse(): void
    {
        [$clientId, $code, $verifier, $redirect] = $this->registerAndAuthorize();
        $this->oauth->exchangeCode($code, $verifier, $clientId, $redirect);

        $this->expectException(OAuthException::class);
        $this->oauth->exchangeCode($code, $verifier, $clientId, $redirect);
    }

    public function testRedirectMismatchRejected(): void
    {
        [$clientId, $code, $verifier] = $this->registerAndAuthorize();

        $this->expectException(OAuthException::class);
        $this->oauth->exchangeCode($code, $verifier, $clientId, 'https://evil.example.com/cb');
    }

    public function testRefreshRotatesAndOldTokenStops(): void
    {
        [$clientId, $code, $verifier, $redirect] = $this->registerAndAuthorize();
        $tokens = $this->oauth->exchangeCode($code, $verifier, $clientId, $redirect);

        $rotated = $this->oauth->refresh($tokens['refresh_token'], $clientId);
        self::assertNotSame($tokens['access_token'], $rotated['access_token']);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);

        // The new access token works; the old refresh token no longer does.
        self::assertNotNull($this->oauth->verifyAccessToken($rotated['access_token']));
    }

    public function testRefreshReuseRevokesFamily(): void
    {
        [$clientId, $code, $verifier, $redirect] = $this->registerAndAuthorize();
        $tokens = $this->oauth->exchangeCode($code, $verifier, $clientId, $redirect);

        $rotated = $this->oauth->refresh($tokens['refresh_token'], $clientId);

        // Reusing the ORIGINAL (already-rotated) refresh token is theft →
        // the whole family is revoked, including the rotated access token.
        try {
            $this->oauth->refresh($tokens['refresh_token'], $clientId);
            self::fail('Expected reuse to be rejected.');
        } catch (OAuthException) {
        }

        self::assertNull($this->oauth->verifyAccessToken($rotated['access_token']));
    }

    public function testInvalidRedirectUriRejectedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Non-loopback http is not allowed.
        $this->oauth->registerClient('Bad', ['http://public.example.com/cb']);
    }

    public function testLoopbackHttpAllowed(): void
    {
        $client = $this->oauth->registerClient('Native', ['http://127.0.0.1:9999/cb']);
        self::assertTrue($this->oauth->redirectAllowed($client['client_id'], 'http://127.0.0.1:9999/cb'));
        self::assertFalse($this->oauth->redirectAllowed($client['client_id'], 'http://127.0.0.1:9999/other'));
    }

    public function testCookieReadIsSchemeGated(): void
    {
        $sid = str_repeat('a', 64);

        // Over HTTPS only the __Host- cookie is honored — a plain `fsid`
        // (which a subdomain could set) is ignored, blocking cookie-tossing.
        self::assertSame($sid, \Fastsite\Identity\Service\SessionService::sessionIdFromCookies(['__Host-fsid' => $sid], true));
        self::assertNull(\Fastsite\Identity\Service\SessionService::sessionIdFromCookies(['fsid' => $sid], true));

        // Over plain HTTP (dev) the plain cookie is accepted.
        self::assertSame($sid, \Fastsite\Identity\Service\SessionService::sessionIdFromCookies(['fsid' => $sid], false));
        // Malformed ids are rejected on both paths.
        self::assertNull(\Fastsite\Identity\Service\SessionService::sessionIdFromCookies(['__Host-fsid' => 'not-hex'], true));
    }

    public function testConsentMemory(): void
    {
        $client = $this->oauth->registerClient('C', ['https://a.example.com/cb']);
        $hash = OAuthService::scopeSetHash(['content:write', 'publish']);

        self::assertFalse($this->oauth->hasConsent($this->userId, $client['client_id'], $hash));
        $this->oauth->recordConsent($this->userId, $client['client_id'], $hash);
        self::assertTrue($this->oauth->hasConsent($this->userId, $client['client_id'], $hash));

        // Scope-set order doesn't matter.
        self::assertSame($hash, OAuthService::scopeSetHash(['publish', 'content:write']));
    }
}
