<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

/**
 * Cloudflare API v4 over curl — hand-rolled in the S3Client spirit (no SDK).
 * The base URL is a constant, never derived from input, so a tampered site
 * row can't point setup calls at another host.
 */
final class CurlCfTransport implements CfTransport
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function request(string $method, string $path, ?array $body, string $token): array
    {
        $ch = curl_init(self::BASE . $path);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize the Cloudflare API request.');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Cloudflare API unreachable: ' . ($error !== '' ? $error : 'unknown curl error'));
        }

        /** @var array<string, mixed> $json */
        $json = json_decode((string) $raw, true) ?: [];

        return ['status' => $status, 'json' => $json];
    }
}
