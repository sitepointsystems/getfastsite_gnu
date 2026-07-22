<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Predis\Client;

/**
 * Web sessions for the dashboard + OAuth consent flow. Redis is the live
 * store (fast, auto-expiring); the user_sessions table backs the
 * "active sessions" UI. Login is rate-limited and enumeration-safe.
 */
final class SessionService
{
    private const COOKIE_SECURE = '__Host-fsid'; // production (HTTPS)
    private const COOKIE_DEV = 'fsid';           // local HTTP only
    private const TTL_SECONDS = 7200;        // 2h idle
    private const LOGIN_LIMIT = 5;
    private const LOGIN_WINDOW = 900;        // 5 tries / 15 min
    private const ACCOUNT_LIMIT = 50;        // IP-independent per-account cap
    // A REAL argon2id hash (same 64MiB/4/2 params as UserService) verified
    // on the user-not-found path so a miss runs the full KDF and takes the
    // same ~110ms as a hit — no timing oracle for account enumeration.
    // A hand-built base64 string would decode-fail and short-circuit.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$V3JyZUUucWRpbURwdlRveQ$Tfm1v4gYn0JQuhfdz7PHIxqSDW0vTUVm299lLubM1nM';

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Client $redis,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The session id from the cookie. Over HTTPS ONLY the __Host- cookie is
     * honored — reading the non-prefixed `fsid` in production would nullify
     * the __Host- hardening and enable cookie-tossing session fixation from
     * a subdomain. The plain `fsid` is accepted only over plain HTTP (dev).
     *
     * @param array<string, mixed> $cookies
     */
    public static function sessionIdFromCookies(array $cookies, bool $secure): ?string
    {
        $value = $secure
            ? ($cookies[self::COOKIE_SECURE] ?? null)
            : ($cookies[self::COOKIE_DEV] ?? null);

        return \is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    public static function isHttps(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        return strtolower($request->getUri()->getScheme()) === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    /**
     * Builds the Set-Cookie header. Over HTTPS uses the __Host- prefix +
     * Secure; over plain HTTP (local dev only) falls back to a plain cookie.
     */
    public static function setCookie(string $sessionId, bool $secure): string
    {
        if ($secure) {
            return self::COOKIE_SECURE . '=' . $sessionId . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=' . self::TTL_SECONDS;
        }

        return self::COOKIE_DEV . '=' . $sessionId . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . self::TTL_SECONDS;
    }

    /**
     * Verifies credentials and starts a session. Returns the session id, or
     * null on bad credentials / rate limit / inactive user. NOTE: does NOT
     * consider TOTP — use LoginFlow::begin for user-facing logins.
     */
    public function login(string $email, string $password, string $ip, string $userAgent): ?string
    {
        $userId = $this->verifyCredentials($email, $password, $ip);

        return $userId !== null ? $this->mintSession($userId, $ip, $userAgent) : null;
    }

    /**
     * Credential check only (rate-limited, enumeration-safe: always runs a
     * hash so timing doesn't reveal whether the email exists). Returns the
     * user id or null.
     */
    public function verifyCredentials(string $email, string $password, string $ip): ?int
    {
        $email = mb_strtolower(trim($email));

        // Two throttles: per (email, ip) — cheap and precise — plus an
        // IP-independent per-account cap so a rotating-IP attacker can't get
        // an unlimited budget against one account. Higher account threshold
        // avoids a trivial single-request lockout DoS.
        $rlKey = 'fastsite:login:' . hash('sha256', $email . '|' . $ip);
        $acctKey = 'fastsite:login:acct:' . hash('sha256', $email);
        if ((int) $this->redis->get($rlKey) >= self::LOGIN_LIMIT || (int) $this->redis->get($acctKey) >= self::ACCOUNT_LIMIT) {
            return null;
        }

        $row = $this->databases->engine()->fetchAssociative(
            'SELECT id, password_hash, is_active FROM users WHERE email = ? AND deleted_at IS NULL',
            [$email],
        );

        // Verify against a valid hash even on a miss so timing can't reveal
        // whether the email exists.
        $hash = $row !== false ? (string) $row['password_hash'] : self::DUMMY_HASH;
        $ok = password_verify($password, $hash);

        if ($row === false || !$ok || (int) $row['is_active'] !== 1) {
            if ((int) $this->redis->incr($rlKey) === 1) {
                $this->redis->expire($rlKey, self::LOGIN_WINDOW);
            }
            if ((int) $this->redis->incr($acctKey) === 1) {
                $this->redis->expire($acctKey, self::LOGIN_WINDOW);
            }

            return null;
        }

        return (int) $row['id'];
    }

    /** Starts a session for an ALREADY-authenticated user id. */
    public function mintSession(int $userId, string $ip, string $userAgent): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $now = $this->clock->iso();
        $expires = $this->clock->now()->add(new \DateInterval('PT' . self::TTL_SECONDS . 'S'))->format('Y-m-d\TH:i:s\Z');

        $this->redis->setex('fastsite:sess:' . $sessionId, self::TTL_SECONDS, (string) $userId);
        $this->databases->engine()->insert('user_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $expires,
        ]);

        $this->databases->engine()->update('users', [
            'last_login_at' => $now,
            'last_login_ip' => $ip,
        ], ['id' => $userId]);

        return $sessionId;
    }

    /**
     * @return array{id: int, email: string, name: string, role: string}|null
     */
    public function resolve(?string $sessionId): ?array
    {
        if ($sessionId === null || $sessionId === '' || preg_match('/^[a-f0-9]{64}$/', $sessionId) !== 1) {
            return null;
        }
        $userId = $this->redis->get('fastsite:sess:' . $sessionId);
        if ($userId === null) {
            return null;
        }
        // Refresh idle TTL.
        $this->redis->expire('fastsite:sess:' . $sessionId, self::TTL_SECONDS);

        $row = $this->databases->engine()->fetchAssociative(
            'SELECT id, email, name, role FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
            [(int) $userId],
        );
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name'], 'role' => (string) $row['role']];
    }

    public function logout(string $sessionId): void
    {
        $this->redis->del(['fastsite:sess:' . $sessionId, 'fastsite:csrf:' . $sessionId]);
        $this->databases->engine()->executeStatement('DELETE FROM user_sessions WHERE id = ?', [$sessionId]);
    }

    /** Kills every session for a user (password change, admin deactivate). */
    public function logoutAllForUser(int $userId, ?string $except = null): void
    {
        $rows = $this->databases->engine()->fetchAllAssociative(
            'SELECT id FROM user_sessions WHERE user_id = ?',
            [$userId],
        );
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if ($except !== null && hash_equals($except, $id)) {
                continue;
            }
            $this->logout($id);
        }
    }

    /**
     * Session-bound CSRF token (created on first use, lives with the
     * session). Forms embed it; CsrfMiddleware enforces it on writes.
     */
    public function csrfToken(string $sessionId): string
    {
        $key = 'fastsite:csrf:' . $sessionId;
        $token = $this->redis->get($key);
        if (!\is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->redis->setex($key, self::TTL_SECONDS, $token);
        } else {
            $this->redis->expire($key, self::TTL_SECONDS);
        }

        return $token;
    }

    public function verifyCsrf(string $sessionId, string $token): bool
    {
        $stored = $this->redis->get('fastsite:csrf:' . $sessionId);

        return \is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }
}
