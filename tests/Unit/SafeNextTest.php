<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Dashboard\Handler\LoginPageHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The login redirect-target guard must not be an open redirect. */
final class SafeNextTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            ['/account', '/account'],
            ['/sites/demo/content?type=post', '/sites/demo/content?type=post'],
            ['/', '/'],
            ['//evil.com', '/'],
            ['/\\evil.com', '/'],           // browsers normalize \ to / → //evil.com
            ['https://evil.com', '/'],
            ['javascript:alert(1)', '/'],
            ['evil.com', '/'],
            ['', '/'],
        ];
    }

    #[DataProvider('cases')]
    public function testSafeNext(string $input, string $expected): void
    {
        self::assertSame($expected, LoginPageHandler::safeNext($input));
    }
}
