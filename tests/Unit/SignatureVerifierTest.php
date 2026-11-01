<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Webhook\Service\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private function clockAt(int $ts): Clock
    {
        return new class($ts) implements Clock {
            public function __construct(private readonly int $ts)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable('@' . $this->ts));
            }

            public function iso(): string
            {
                return $this->now()->format('Y-m-d\TH:i:s\Z');
            }
        };
    }

    public function testValidSignaturePasses(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $header = $verifier->sign('{"a":1}', 'secret', 1_000_000);

        self::assertTrue($verifier->verify($header, '{"a":1}', 'secret'));
    }

    public function testWrongSecretFails(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $header = $verifier->sign('{"a":1}', 'secret', 1_000_000);

        self::assertFalse($verifier->verify($header, '{"a":1}', 'other-secret'));
    }

    public function testTamperedBodyFails(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $header = $verifier->sign('{"a":1}', 'secret', 1_000_000);

        self::assertFalse($verifier->verify($header, '{"a":2}', 'secret'));
    }

    public function testStaleTimestampFails(): void
    {
        $signer = new SignatureVerifier($this->clockAt(1_000_000));
        $header = $signer->sign('{"a":1}', 'secret', 1_000_000);

        // Verifier clock is 10 minutes later — beyond the 300s window.
        $verifier = new SignatureVerifier($this->clockAt(1_000_600));
        self::assertFalse($verifier->verify($header, '{"a":1}', 'secret'));
    }

    public function testMalformedHeaderFails(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));

        self::assertFalse($verifier->verify('garbage', '{}', 'secret'));
        self::assertFalse($verifier->verify('t=1000000', '{}', 'secret'));
        self::assertFalse($verifier->verify('v1=abc', '{}', 'secret'));
    }

    public function testEmptySecretNeverVerifies(): void
    {
        // An empty HMAC key is publicly reproducible — must always reject,
        // even against a signature computed with the empty key.
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $forged = $verifier->sign('{"a":1}', '', 1_000_000);

        self::assertFalse($verifier->verify($forged, '{"a":1}', ''));
    }

    public function testPlainSignatureVerifies(): void
    {
        // The AutoSEO scheme: hex HMAC of the raw body alone, no timestamp.
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $sig = hash_hmac('sha256', '{"a":1}', 'aseo_wh_token');

        self::assertTrue($verifier->verifyPlain($sig, '{"a":1}', 'aseo_wh_token'));
        self::assertTrue($verifier->verifyPlain('sha256=' . $sig, '{"a":1}', 'aseo_wh_token'));
        self::assertTrue($verifier->verifyPlain(strtoupper($sig), '{"a":1}', 'aseo_wh_token'));

        self::assertFalse($verifier->verifyPlain($sig, '{"a":2}', 'aseo_wh_token'));
        self::assertFalse($verifier->verifyPlain($sig, '{"a":1}', 'other-secret'));
        self::assertFalse($verifier->verifyPlain('garbage', '{"a":1}', 'aseo_wh_token'));
        self::assertFalse($verifier->verifyPlain('', '{"a":1}', 'aseo_wh_token'));
    }

    public function testPlainSignatureEmptySecretNeverVerifies(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));
        $forged = hash_hmac('sha256', '{"a":1}', '');

        self::assertFalse($verifier->verifyPlain($forged, '{"a":1}', ''));
    }

    public function testBearerVerifies(): void
    {
        $verifier = new SignatureVerifier($this->clockAt(1_000_000));

        self::assertTrue($verifier->verifyBearer('Bearer aseo_wh_token', 'aseo_wh_token'));
        self::assertTrue($verifier->verifyBearer('bearer aseo_wh_token', 'aseo_wh_token'));

        self::assertFalse($verifier->verifyBearer('Bearer wrong', 'aseo_wh_token'));
        self::assertFalse($verifier->verifyBearer('aseo_wh_token', 'aseo_wh_token'));
        self::assertFalse($verifier->verifyBearer('', 'aseo_wh_token'));
        self::assertFalse($verifier->verifyBearer('Bearer ', ''));
    }
}
