<?php

declare(strict_types=1);

namespace Fastsite\Access\Service;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Predis\Client;

/**
 * API keys: token format `fs_<key_id>_<secret>` shown ONCE at creation;
 * secret stored argon2id-hashed. Verification goes through a short Redis
 * cache keyed by sha256(token) so argon2id (deliberately slow) is paid at
 * most once per five minutes per key. Rotation = new row + revoke old.
 */
final class ApiKeyService
{
    public const SCOPES = [
        'sites:read', 'sites:write', 'content:read', 'content:write', 'media:read', 'media:write',
        'components:write', 'shell:read', 'shell:write', 'translations:manage',
        'publish', 'jobs:read', 'backup:read', 'backup:run', 'backup:restore', 'admin',
    ];

    private const CACHE_TTL = 300;
    private const LAST_USED_THROTTLE = 60;
    private const VERIFY_FAIL_LIMIT = 10;
    private const VERIFY_FAIL_WINDOW = 60;

    private const ARGON2_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Client $redis,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $sites handles or ["*"]
     * @return array{token: string, key_id: string, id: int}
     */
    public function create(int $userId, string $name, array $scopes, array $sites = ['*'], ?string $expiresAt = null): array
    {
        foreach ($scopes as $scope) {
            if (!\in_array($scope, self::SCOPES, true)) {
                throw new \InvalidArgumentException('Unknown scope: ' . $scope);
            }
        }
        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one scope is required.');
        }

        $keyId = self::base62(8);
        $secret = self::base62(32);

        $db = $this->databases->engine();
        $db->insert('api_keys', [
            'user_id' => $userId,
            'name' => $name,
            'key_id' => $keyId,
            'secret_hash' => password_hash($secret, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS),
            'scopes' => json_encode(array_values($scopes), JSON_THROW_ON_ERROR),
            'sites' => json_encode(array_values($sites), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => $this->clock->iso(),
        ]);

        return [
            'token' => 'fs_' . $keyId . '_' . $secret,
            'key_id' => $keyId,
            'id' => (int) $db->lastInsertId(),
        ];
    }

    /**
     * Verifies a raw token. Returns null on any failure (invalid format,
     * unknown key, bad secret, revoked, expired, inactive user).
     */
    public function verify(string $token, string $ip = ''): ?AccessContext
    {
        if (preg_match('/^fs_([A-Za-z0-9]{8})_([A-Za-z0-9]{32})$/', $token, $m) !== 1) {
            return null;
        }
        [, $keyId, $secret] = $m;

        $cacheKey = 'fastsite:akv:' . hash('sha256', $token);
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            $data = json_decode($cached, true);
            // A cached positive is only trusted while the user's generation
            // marker still matches — deactivation/soft-delete bumps it and
            // invalidates every warm cache for that user immediately.
            if (\is_array($data) && (int) $this->redis->get('fastsite:akg:' . (int) $data['uid']) === (int) $data['gen']) {
                $this->touchLastUsed((int) $data['id'], $ip);

                return new AccessContext('api_key', (int) $data['uid'], (int) $data['id'], $data['scopes'], $data['sites']);
            }
            $this->redis->del([$cacheKey]);
        }

        // Throttle repeated bad secrets against a live key_id BEFORE paying
        // for argon2id — otherwise a rotating-secret flood is a CPU DoS.
        $failKey = 'fastsite:akf:' . $keyId;
        if ((int) $this->redis->get($failKey) >= self::VERIFY_FAIL_LIMIT) {
            return null;
        }

        $row = $this->databases->engine()->fetchAssociative(
            'SELECT k.*, u.is_active AS user_active, u.deleted_at AS user_deleted
             FROM api_keys k JOIN users u ON u.id = k.user_id
             WHERE k.key_id = ?',
            [$keyId],
        );
        if ($row === false || $row['revoked_at'] !== null) {
            return null;
        }
        if ((int) $row['user_active'] !== 1 || $row['user_deleted'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && (string) $row['expires_at'] <= $this->clock->iso()) {
            return null;
        }
        if (!password_verify($secret, (string) $row['secret_hash'])) {
            $this->recordVerifyFailure($failKey);

            return null;
        }
        // A revoke() that committed between our row read and here must not
        // be repopulated — re-check the tombstone before caching.
        if ($this->redis->get('fastsite:akr:' . (int) $row['id']) !== null) {
            return null;
        }

        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true) ?: [];
        /** @var list<string> $sites */
        $sites = json_decode((string) $row['sites'], true) ?: [];
        $id = (int) $row['id'];
        $uid = (int) $row['user_id'];

        // TTL never outlives the key's own expiry.
        $ttl = self::CACHE_TTL;
        if ($row['expires_at'] !== null) {
            $remaining = (new \DateTimeImmutable((string) $row['expires_at']))->getTimestamp() - $this->clock->now()->getTimestamp();
            if ($remaining <= 0) {
                return null;
            }
            $ttl = max(1, min($ttl, $remaining));
        }

        $gen = (int) $this->redis->get('fastsite:akg:' . $uid);
        $this->redis->setex($cacheKey, $ttl, json_encode([
            'id' => $id,
            'uid' => $uid,
            'gen' => $gen,
            'scopes' => $scopes,
            'sites' => $sites,
        ], JSON_THROW_ON_ERROR));
        // Pointer from key row to its live cache entry so revoke() can
        // invalidate immediately despite only knowing the row id.
        $this->redis->setex('fastsite:akc:' . $id, $ttl, $cacheKey);

        $this->touchLastUsed($id, $ip);

        return new AccessContext('api_key', $uid, $id, $scopes, $sites);
    }

    /**
     * Invalidates every warm API-key cache for a user (call on deactivate /
     * soft-delete / password reset).
     */
    public function invalidateUser(int $userId): void
    {
        $this->redis->incr('fastsite:akg:' . $userId);
    }

    private function recordVerifyFailure(string $failKey): void
    {
        if ((int) $this->redis->incr($failKey) === 1) {
            $this->redis->expire($failKey, self::VERIFY_FAIL_WINDOW);
        }
    }

    /**
     * Rotation = insert replacement + revoke original (audit-friendly).
     *
     * @return array{token: string, key_id: string, id: int}
     */
    public function rotate(int $keyRowId): array
    {
        $db = $this->databases->engine();
        $row = $db->fetchAssociative('SELECT * FROM api_keys WHERE id = ? AND revoked_at IS NULL', [$keyRowId]);
        if ($row === false) {
            throw new \RuntimeException('API key not found or already revoked.');
        }

        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true) ?: [];
        /** @var list<string> $sites */
        $sites = json_decode((string) $row['sites'], true) ?: [];

        $new = $this->create((int) $row['user_id'], (string) $row['name'], $scopes, $sites, $row['expires_at'] !== null ? (string) $row['expires_at'] : null);
        $this->revoke($keyRowId);

        return $new;
    }

    /**
     * Permanently delete an ALREADY-REVOKED key row (+ its cache tombstone /
     * pointer). Refuses to delete a live key — revoke first.
     */
    public function delete(int $keyRowId): void
    {
        $db = $this->databases->engine();
        $row = $db->fetchAssociative('SELECT revoked_at FROM api_keys WHERE id = ?', [$keyRowId]);
        if ($row === false) {
            throw new \RuntimeException('API key not found.');
        }
        if ($row['revoked_at'] === null) {
            throw new \RuntimeException('Revoke the key before deleting it permanently.');
        }

        $db->executeStatement('DELETE FROM api_keys WHERE id = ?', [$keyRowId]);
        try {
            $this->redis->del(['fastsite:akr:' . $keyRowId, 'fastsite:akc:' . $keyRowId]);
        } catch (\Throwable) {
            // Best-effort — the row is already gone from the DB.
        }
    }

    public function revoke(int $keyRowId): void
    {
        // The DB write is the source of truth — it must succeed even if Redis
        // is unavailable (a Redis error here must not make a completed revoke
        // look like a failure to the caller). Cache invalidation is best-effort;
        // the 300s positive-cache TTL bounds any staleness, and with Redis down
        // verify() falls back to the DB, which now shows the key revoked.
        $this->databases->engine()->update('api_keys', ['revoked_at' => $this->clock->iso()], ['id' => $keyRowId]);

        try {
            // Tombstone first: a verify() racing this revoke re-checks the
            // tombstone before caching, so it can't repopulate the entry we're
            // about to delete.
            $this->redis->setex('fastsite:akr:' . $keyRowId, self::CACHE_TTL, '1');

            // Revocation is immediate: the pointer key tells us which cache
            // entry this row populated.
            $pointer = 'fastsite:akc:' . $keyRowId;
            $cacheKey = $this->redis->get($pointer);
            $this->redis->del($cacheKey !== null ? [$cacheKey, $pointer] : [$pointer]);
        } catch (\Throwable) {
            // Best-effort cache invalidation — the DB revoke already applied.
        }
    }

    private function touchLastUsed(int $id, string $ip): void
    {
        $throttle = 'fastsite:aklu:' . $id;
        if ($this->redis->set($throttle, '1', 'EX', self::LAST_USED_THROTTLE, 'NX') === null) {
            return;
        }

        $this->databases->engine()->update('api_keys', [
            'last_used_at' => $this->clock->iso(),
            'last_used_ip' => $ip,
        ], ['id' => $id]);
    }

    private static function base62(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, 61)];
        }

        return $out;
    }
}
