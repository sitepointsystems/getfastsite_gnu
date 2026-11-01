<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Ramsey\Uuid\Uuid;

/**
 * OAuth 2.1 authorization server. Public clients only (PKCE S256, no
 * secret). Auth codes are single-use + 60s; access tokens 1h, refresh 30d
 * with rotation and family revocation on reuse. Issued tokens are stored
 * SHA-256 hashed (never plaintext). Scopes reuse the API-key vocabulary so
 * one AccessContext model serves every transport.
 */
final class OAuthService
{
    private const CODE_TTL = 60;
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 2592000; // 30 days

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function supportedScopes(): array
    {
        return ApiKeyService::SCOPES;
    }

    /**
     * Dynamic client registration (RFC 7591). Public client.
     *
     * @param list<string> $redirectUris
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}
     */
    public function registerClient(string $clientName, array $redirectUris, string $logoUri = '', string $clientUri = ''): array
    {
        if ($redirectUris === []) {
            throw new \InvalidArgumentException('At least one redirect_uri is required.');
        }
        foreach ($redirectUris as $uri) {
            $this->assertValidRedirect($uri);
        }

        $clientId = 'fsc_' . bin2hex(random_bytes(16));
        $this->databases->engine()->insert('oauth_clients', [
            'client_id' => $clientId,
            'client_name' => mb_substr($clientName, 0, 200),
            'redirect_uris' => json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR),
            'logo_uri' => $logoUri,
            'client_uri' => $clientUri,
            'is_dynamic' => 1,
            'created_at' => $this->clock->iso(),
        ]);

        return ['client_id' => $clientId, 'client_name' => $clientName, 'redirect_uris' => array_values($redirectUris)];
    }

    /**
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, logo_uri: string}|null
     */
    public function getClient(string $clientId): ?array
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM oauth_clients WHERE client_id = ? AND revoked_at IS NULL',
            [$clientId],
        );
        if ($row === false) {
            return null;
        }
        /** @var list<string> $uris */
        $uris = json_decode((string) $row['redirect_uris'], true) ?: [];

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'redirect_uris' => $uris,
            'logo_uri' => (string) $row['logo_uri'],
        ];
    }

    public function redirectAllowed(string $clientId, string $redirectUri): bool
    {
        $client = $this->getClient($clientId);

        return $client !== null && \in_array($redirectUri, $client['redirect_uris'], true);
    }

    /**
     * Issues a single-use authorization code (returned plaintext once).
     *
     * @param list<string> $scopes
     */
    public function issueCode(string $clientId, int $userId, array $scopes, string $codeChallenge, string $redirectUri): string
    {
        $code = 'fsoc_' . bin2hex(random_bytes(32));
        $this->databases->engine()->insert('oauth_codes', [
            'code_hash' => hash('sha256', $code),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => json_encode(array_values($scopes), JSON_THROW_ON_ERROR),
            'code_challenge' => $codeChallenge,
            'redirect_uri' => $redirectUri,
            'expires_at' => $this->clock->now()->add(new \DateInterval('PT' . self::CODE_TTL . 'S'))->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->clock->iso(),
        ]);

        return $code;
    }

    /**
     * Exchanges an auth code + PKCE verifier for a token pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public function exchangeCode(string $code, string $codeVerifier, string $clientId, string $redirectUri): array
    {
        $db = $this->databases->engine();
        $row = $db->fetchAssociative('SELECT * FROM oauth_codes WHERE code_hash = ?', [hash('sha256', $code)]);
        if ($row === false) {
            throw new OAuthException('invalid_grant', 'Unknown authorization code.');
        }

        // Atomically CLAIM the code before any other check or token issuance,
        // so two concurrent exchanges of the same code can't both mint a
        // token family. The single winner (1 row affected) proceeds; a loser
        // (or a sequential replay) is a reused code — deny.
        $claimed = $db->executeStatement(
            'UPDATE oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL',
            [$this->clock->iso(), $row['code_hash']],
        );
        if ($claimed !== 1) {
            throw new OAuthException('invalid_grant', 'Authorization code already used.');
        }

        if ((string) $row['client_id'] !== $clientId) {
            throw new OAuthException('invalid_grant', 'Client mismatch.');
        }
        if ((string) $row['redirect_uri'] !== $redirectUri) {
            throw new OAuthException('invalid_grant', 'redirect_uri mismatch.');
        }
        if ((string) $row['expires_at'] <= $this->clock->iso()) {
            throw new OAuthException('invalid_grant', 'Authorization code expired.');
        }

        // PKCE S256: BASE64URL(SHA256(verifier)) == stored challenge.
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row['code_challenge'], $computed)) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }

        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true) ?: [];

        return $this->issueTokens($clientId, (int) $row['user_id'], $scopes, Uuid::uuid4()->toString());
    }

    /**
     * Refreshes a token pair (rotation). Reuse of a rotated/revoked refresh
     * token revokes the entire family.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $db = $this->databases->engine();
        $row = $db->fetchAssociative('SELECT * FROM oauth_tokens WHERE refresh_hash = ?', [hash('sha256', $refreshToken)]);
        if ($row === false) {
            throw new OAuthException('invalid_grant', 'Unknown refresh token.');
        }
        if ((string) $row['client_id'] !== $clientId) {
            throw new OAuthException('invalid_grant', 'Client mismatch.');
        }
        if ($row['revoked_at'] !== null) {
            // Reuse of a revoked refresh token — revoke the whole family.
            $db->executeStatement('UPDATE oauth_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [$this->clock->iso(), $row['family_id']]);
            throw new OAuthException('invalid_grant', 'Refresh token reuse detected — session revoked.');
        }
        if ($row['refresh_expires_at'] !== null && (string) $row['refresh_expires_at'] <= $this->clock->iso()) {
            throw new OAuthException('invalid_grant', 'Refresh token expired.');
        }

        // Rotate via an atomic compare-and-swap so two concurrent refreshes
        // of the same token can't both succeed (which would defeat theft
        // detection). Exactly one caller claims the row; the loser treats it
        // as reuse and revokes the family.
        $claimed = $db->executeStatement(
            'UPDATE oauth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$this->clock->iso(), (int) $row['id']],
        );
        if ($claimed !== 1) {
            $db->executeStatement('UPDATE oauth_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [$this->clock->iso(), $row['family_id']]);
            throw new OAuthException('invalid_grant', 'Refresh token reuse detected — session revoked.');
        }

        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true) ?: [];

        return $this->issueTokens($clientId, (int) $row['user_id'], $scopes, (string) $row['family_id']);
    }

    public function verifyAccessToken(string $token): ?AccessContext
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT t.*, u.is_active AS user_active, u.deleted_at AS user_deleted, u.role AS user_role
             FROM oauth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.access_hash = ?',
            [hash('sha256', $token)],
        );
        if ($row === false || $row['revoked_at'] !== null) {
            return null;
        }
        if ((int) $row['user_active'] !== 1 || $row['user_deleted'] !== null) {
            return null;
        }
        if ((string) $row['access_expires_at'] <= $this->clock->iso()) {
            return null;
        }

        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true) ?: [];

        // The 'admin' scope implies every other scope (AccessContext), so cap
        // it by the user's actual role — a non-admin consenting to a client
        // that requests 'admin' must not mint an all-scope token.
        if ((string) $row['user_role'] !== 'admin') {
            $scopes = array_values(array_diff($scopes, ['admin']));
        }

        // OAuth grants act across all the user's sites (site scoping is via
        // the user's site_users membership, enforced per-tool in the MCP layer).
        return new AccessContext('oauth', (int) $row['user_id'], null, $scopes, ['*']);
    }

    public function revoke(string $token): void
    {
        $hash = hash('sha256', $token);
        $this->databases->engine()->executeStatement(
            'UPDATE oauth_tokens SET revoked_at = ? WHERE (access_hash = ? OR refresh_hash = ?) AND revoked_at IS NULL',
            [$this->clock->iso(), $hash, $hash],
        );
    }

    public function hasConsent(int $userId, string $clientId, string $scopeSetHash): bool
    {
        return (bool) $this->databases->engine()->fetchOne(
            'SELECT 1 FROM oauth_consents WHERE user_id = ? AND client_id = ? AND scope_set_hash = ?',
            [$userId, $clientId, $scopeSetHash],
        );
    }

    public function recordConsent(int $userId, string $clientId, string $scopeSetHash): void
    {
        $this->databases->engine()->executeStatement(
            'INSERT INTO oauth_consents (user_id, client_id, scope_set_hash, granted_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, client_id, scope_set_hash) DO NOTHING',
            [$userId, $clientId, $scopeSetHash, $this->clock->iso()],
        );
    }

    /**
     * @param list<string> $scopes
     */
    public static function scopeSetHash(array $scopes): string
    {
        $sorted = $scopes;
        sort($sorted);

        return hash('sha256', implode(' ', $sorted));
    }

    /**
     * @param list<string> $scopes
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    private function issueTokens(string $clientId, int $userId, array $scopes, string $familyId): array
    {
        $access = 'fsoa_' . bin2hex(random_bytes(32));
        $refresh = 'fsor_' . bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $this->databases->engine()->insert('oauth_tokens', [
            'family_id' => $familyId,
            'access_hash' => hash('sha256', $access),
            'refresh_hash' => hash('sha256', $refresh),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => json_encode(array_values($scopes), JSON_THROW_ON_ERROR),
            'access_expires_at' => $now->add(new \DateInterval('PT' . self::ACCESS_TTL . 'S'))->format('Y-m-d\TH:i:s\Z'),
            'refresh_expires_at' => $now->add(new \DateInterval('PT' . self::REFRESH_TTL . 'S'))->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->clock->iso(),
        ]);

        $this->databases->engine()->update('oauth_clients', ['last_used_at' => $this->clock->iso()], ['client_id' => $clientId]);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => self::ACCESS_TTL,
            'scope' => implode(' ', $scopes),
        ];
    }

    private function assertValidRedirect(string $uri): void
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid redirect_uri: ' . $uri);
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        // https anywhere; http only for loopback (native app localhost flow).
        $isLoopback = \in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && $isLoopback) {
            return;
        }
        // Custom scheme (native app) is allowed.
        if (!\in_array($scheme, ['http', 'https'], true) && $host !== '') {
            return;
        }
        throw new \InvalidArgumentException('redirect_uri must be https, http-loopback, or a custom scheme: ' . $uri);
    }
}
