<?php

declare(strict_types=1);

namespace Fastsite\Tests\Shared;

use Fastsite\Shared\Crypto\TokenCipher;
use PHPUnit\Framework\TestCase;

final class TokenCipherTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cipher = new TokenCipher('test-secret');

        $encrypted = $cipher->encrypt('deploy-key-material');
        self::assertNotNull($encrypted);
        self::assertNotSame('deploy-key-material', $encrypted);
        self::assertSame('deploy-key-material', $cipher->decrypt($encrypted));
    }

    public function testArrayRoundTrip(): void
    {
        $cipher = new TokenCipher('test-secret');

        $encrypted = $cipher->encryptArray(['token' => 'abc', 'n' => 7]);
        self::assertNotNull($encrypted);
        self::assertSame(['token' => 'abc', 'n' => 7], $cipher->decryptArray($encrypted));
    }

    public function testTamperedCiphertextFailsAuthentication(): void
    {
        $cipher = new TokenCipher('test-secret');
        $encrypted = $cipher->encrypt('secret');
        self::assertNotNull($encrypted);

        $raw = base64_decode($encrypted, true);
        self::assertNotFalse($raw);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] === 'a' ? 'b' : 'a';

        self::assertNull($cipher->decrypt(base64_encode($raw)));
    }

    public function testWrongKeyFails(): void
    {
        $encrypted = (new TokenCipher('key-one'))->encrypt('secret');
        self::assertNotNull($encrypted);
        self::assertNull((new TokenCipher('key-two'))->decrypt($encrypted));
    }

    public function testUnconfiguredReturnsNull(): void
    {
        $cipher = new TokenCipher('');
        self::assertFalse($cipher->isConfigured());
        self::assertNull($cipher->encrypt('x'));
    }
}
