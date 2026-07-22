<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Predis\Client;

/**
 * The one user-facing login sequence (dashboard + OAuth authorize):
 * credentials → optional TOTP challenge → session. The TOTP challenge is a
 * short-lived Redis token so no half-authenticated session ever exists.
 */
final class LoginFlow
{
    private const CHALLENGE_TTL = 300;
    private const CHALLENGE_MAX_TRIES = 5;
    // Cross-challenge cap: minting a fresh challenge must NOT reset the 2FA
    // guess budget, or an attacker who has the password could loop new
    // challenges and brute-force the 6-digit code. Keyed on the user id.
    private const USER_2FA_WINDOW = 900;
    private const USER_2FA_MAX_FAILS = 10;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly TotpService $totp,
        private readonly Client $redis,
    ) {
    }

    /**
     * @return array{status: 'bad'}|array{status: 'ok', session: string}|array{status: 'totp', challenge: string}
     */
    public function begin(string $email, string $password, string $ip, string $userAgent): array
    {
        $userId = $this->sessions->verifyCredentials($email, $password, $ip);
        if ($userId === null) {
            return ['status' => 'bad'];
        }

        if ($this->totp->isEnabled($userId)) {
            // A user already over the 2FA failure cap gets no new challenge —
            // the loop-fresh-challenges brute force is dead.
            if ((int) $this->redis->get('fastsite:2fa-fail:' . $userId) >= self::USER_2FA_MAX_FAILS) {
                return ['status' => 'bad'];
            }
            $challenge = bin2hex(random_bytes(32));
            $this->redis->setex('fastsite:2fa:' . $challenge, self::CHALLENGE_TTL, (string) $userId);

            return ['status' => 'totp', 'challenge' => $challenge];
        }

        return ['status' => 'ok', 'session' => $this->sessions->mintSession($userId, $ip, $userAgent)];
    }

    /**
     * Verifies the TOTP code for a pending challenge. Wrong code keeps the
     * challenge alive (bounded retries); success or exhaustion burns it.
     */
    public function completeTotp(string $challenge, string $code, string $ip, string $userAgent): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $challenge) !== 1) {
            return null;
        }
        $key = 'fastsite:2fa:' . $challenge;
        $userId = $this->redis->get($key);
        if (!\is_string($userId) || $userId === '') {
            return null;
        }

        // Durable per-user failure cap survives challenge rotation.
        $failKey = 'fastsite:2fa-fail:' . $userId;
        if ((int) $this->redis->get($failKey) >= self::USER_2FA_MAX_FAILS) {
            $this->redis->del([$key]);

            return null;
        }

        $triesKey = $key . ':tries';
        if ((int) $this->redis->incr($triesKey) === 1) {
            $this->redis->expire($triesKey, self::CHALLENGE_TTL);
        }
        if ((int) $this->redis->get($triesKey) > self::CHALLENGE_MAX_TRIES) {
            $this->redis->del([$key, $triesKey]);

            return null;
        }

        if (!$this->totp->verifyForUser((int) $userId, $code)) {
            if ((int) $this->redis->incr($failKey) === 1) {
                $this->redis->expire($failKey, self::USER_2FA_WINDOW);
            }

            return null;
        }

        $this->redis->del([$key, $triesKey, $failKey]);

        return $this->sessions->mintSession((int) $userId, $ip, $userAgent);
    }

    /** User id behind a just-minted session (remember-me issuance). */
    public function userIdForFreshSession(string $sessionId): ?int
    {
        $user = $this->sessions->resolve($sessionId);

        return $user !== null ? $user['id'] : null;
    }
}
