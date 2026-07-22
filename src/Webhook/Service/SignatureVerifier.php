<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Service;

use Fastsite\Shared\Clock\Clock;

/**
 * Stripe-style timestamped HMAC verification for inbound webhooks.
 * Header format:  X-Fastsite-Signature: t=<unix>,v1=<hex hmac-sha256>
 * The signed payload is "<t>.<raw body>", HMAC'd with the integration
 * secret. A ±300s window bounds replay; hash_equals is constant-time.
 * (Conzent AutoSeo SignatureVerifier pattern.)
 */
final class SignatureVerifier
{
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function verify(string $signatureHeader, string $rawBody, string $secret): bool
    {
        // Defense in depth: an empty key produces a publicly reproducible
        // HMAC, so never accept a signature verified against no secret.
        if ($secret === '') {
            return false;
        }

        $parts = $this->parseHeader($signatureHeader);
        if ($parts === null) {
            return false;
        }
        [$timestamp, $signature] = $parts;

        $age = abs($this->clock->now()->getTimestamp() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Builds a signature header for a body — used by tests and by any
     * outbound-signing we add later.
     */
    public function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= $this->clock->now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    /**
     * @return array{int, string}|null
     */
    private function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signature = null;
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (\count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signature = $kv[1];
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            return null;
        }

        return [$timestamp, $signature];
    }
}
