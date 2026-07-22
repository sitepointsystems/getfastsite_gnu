<?php

declare(strict_types=1);

namespace Fastsite\Shared\Crypto;

/**
 * Authenticated symmetric encryption for replayable secrets stored at rest
 * (deploy keys, GitHub PATs, webhook secrets, endpointr/R2 keys, TOTP seeds).
 * Own issued tokens are hashed, never encrypted — see plan §access.
 *
 * AES-256-GCM via openssl. Key derived from APP_SECRET (any length,
 * normalised to 32 bytes via SHA-256). Ported from conzent's TokenCipher.
 *
 * Ciphertext format (base64 of): [12-byte IV][16-byte GCM tag][ciphertext]
 */
final class TokenCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public function __construct(private readonly string $secret)
    {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    public function encrypt(string $plaintext): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            return null;
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || \strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function encryptArray(array $data): ?string
    {
        try {
            return $this->encrypt(json_encode($data, \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decryptArray(string $payload): ?array
    {
        $json = $this->decrypt($payload);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function key(): ?string
    {
        if ($this->secret === '') {
            return null;
        }

        return hash('sha256', $this->secret, true);
    }
}
