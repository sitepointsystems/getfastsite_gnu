<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Predis\Client;

/**
 * Opt-in TOTP (RFC 6238, SHA-1/30s/6 digits — the authenticator-app
 * standard), hand-rolled to keep the dependency tree lean. The seed is
 * TokenCipher-encrypted at rest (users.totp_seed_enc); codes are checked
 * with ±1 step of clock drift and a Redis replay guard so a captured code
 * cannot be used twice inside its window.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const DRIFT_STEPS = 1;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly TokenCipher $cipher,
        private readonly Clock $clock,
        private readonly Client $redis,
    ) {
    }

    /** 160-bit random seed, base32 (what authenticator apps accept). */
    public function generateSeed(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** otpauth:// URI for the QR/manual entry in the account screen. */
    public function provisioningUri(string $email, string $seed): string
    {
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode('fastsite:' . $email),
            $seed,
            rawurlencode('fastsite'),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Verifies a code against a seed with drift tolerance + replay guard.
     * $replayScope isolates the guard per user.
     */
    public function verify(string $seed, string $code, int $replayScope): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return false;
        }

        $key = self::base32Decode($seed);
        if ($key === '') {
            return false;
        }

        $step = intdiv((int) $this->clock->now()->getTimestamp(), self::PERIOD);
        for ($i = -self::DRIFT_STEPS; $i <= self::DRIFT_STEPS; ++$i) {
            if (!hash_equals(self::hotp($key, $step + $i), $code)) {
                continue;
            }
            // Replay guard: this (user, step) pair burns once.
            $guard = 'fastsite:totp:' . $replayScope . ':' . ($step + $i);
            if ($this->redis->set($guard, '1', 'EX', self::PERIOD * (2 * self::DRIFT_STEPS + 1), 'NX') === null) {
                return false;
            }

            return true;
        }

        return false;
    }

    /** Whether the user has TOTP enabled. */
    public function isEnabled(int $userId): bool
    {
        $seed = $this->databases->engine()->fetchOne('SELECT totp_seed_enc FROM users WHERE id = ?', [$userId]);

        return $seed !== false && $seed !== null && $seed !== '';
    }

    /**
     * Enables TOTP for the user after they prove possession of the seed by
     * verifying one code against it.
     */
    public function enable(int $userId, string $seed, string $code): bool
    {
        if (!$this->verify($seed, $code, $userId)) {
            return false;
        }
        $this->databases->engine()->update('users', [
            'totp_seed_enc' => $this->cipher->encrypt($seed),
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);

        return true;
    }

    /** Disabling requires a valid current code (stolen-session hardening). */
    public function disable(int $userId, string $code): bool
    {
        $seed = $this->seedFor($userId);
        if ($seed === null || !$this->verify($seed, $code, $userId)) {
            return false;
        }
        $this->databases->engine()->update('users', [
            'totp_seed_enc' => null,
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);

        return true;
    }

    /** Verifies a login-time code for a user with TOTP enabled. */
    public function verifyForUser(int $userId, string $code): bool
    {
        $seed = $this->seedFor($userId);

        return $seed !== null && $this->verify($seed, $code, $userId);
    }

    private function seedFor(int $userId): ?string
    {
        $enc = $this->databases->engine()->fetchOne('SELECT totp_seed_enc FROM users WHERE id = ?', [$userId]);
        if ($enc === false || $enc === null || $enc === '') {
            return null;
        }

        try {
            return $this->cipher->decrypt((string) $enc);
        } catch (\Throwable) {
            return null; // APP_SECRET rotated — seed unrecoverable, treat as unset
        }
    }

    private static function hotp(string $key, int $counter): string
    {
        $binCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | \ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::B32[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($value << (5 - $bits)) & 31];
        }

        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(str_replace([' ', '='], '', $b32));
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::B32, $char);
            if ($pos === false) {
                return '';
            }
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $out .= \chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $out;
    }
}
