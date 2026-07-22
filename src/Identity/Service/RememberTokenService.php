<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Remember-me: cookie "{selector}.{validator}", 30 days. Selector is the
 * DB lookup key; only sha256(validator) is stored (a DB leak mints nothing).
 * Every successful use rotates the token — a replayed old cookie fails and
 * revokes the user's whole set (theft signal, cf. refresh-token families).
 */
final class RememberTokenService
{
    private const COOKIE_SECURE = '__Host-fsrm';
    private const COOKIE_DEV = 'fsrm';
    private const TTL_DAYS = 30;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /** Creates a token and returns the cookie VALUE ("selector.validator"). */
    public function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $this->databases->engine()->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'created_at' => $this->clock->iso(),
            'expires_at' => $this->clock->now()->add(new \DateInterval('P' . self::TTL_DAYS . 'D'))->format('Y-m-d\TH:i:s\Z'),
        ]);

        return $selector . '.' . $validator;
    }

    /**
     * Validates a cookie value and ROTATES it. Returns
     * [userId, freshCookieValue] or null. A valid selector with a wrong
     * validator = stolen-then-rotated cookie replay → revoke all.
     *
     * @return array{0: int, 1: string}|null
     */
    public function consume(string $cookieValue): ?array
    {
        if (preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $cookieValue, $m) !== 1) {
            return null;
        }
        [, $selector, $validator] = $m;

        $db = $this->databases->engine();
        $row = $db->fetchAssociative(
            'SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?',
            [$selector],
        );
        if ($row === false) {
            return null;
        }

        $userId = (int) $row['user_id'];

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Selector matched, validator didn't: someone is replaying a
            // rotated-away cookie. Kill every remember token for the user.
            $this->revokeAll($userId);

            return null;
        }

        // Single-use: only the caller that deletes the row may mint a session
        // (two racing requests with the same cookie → one winner).
        $claimed = $db->executeStatement('DELETE FROM remember_tokens WHERE id = ?', [(int) $row['id']]);
        if ($claimed !== 1 || (string) $row['expires_at'] <= $this->clock->iso()) {
            return null;
        }

        return [$userId, $this->issue($userId)];
    }

    public function revokeAll(int $userId): void
    {
        $this->databases->engine()->executeStatement('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    }

    /**
     * @param array<string, mixed> $cookies
     */
    public static function cookieValue(array $cookies, bool $secure): ?string
    {
        $value = $secure ? ($cookies[self::COOKIE_SECURE] ?? null) : ($cookies[self::COOKIE_DEV] ?? null);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public static function setCookie(string $value, bool $secure): string
    {
        $maxAge = self::TTL_DAYS * 86400;
        if ($secure) {
            return self::COOKIE_SECURE . '=' . $value . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=' . $maxAge;
        }

        return self::COOKIE_DEV . '=' . $value . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . $maxAge;
    }

    public static function clearCookie(bool $secure): string
    {
        $name = $secure ? self::COOKIE_SECURE : self::COOKIE_DEV;

        return $name . '=; Path=/; HttpOnly; ' . ($secure ? 'Secure; ' : '') . 'SameSite=Lax; Max-Age=0';
    }
}
