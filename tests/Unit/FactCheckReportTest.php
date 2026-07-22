<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\FactCheck\FactCheckException;
use Fastsite\FactCheck\FactCheckReport;
use Fastsite\FactCheck\NullFactChecker;
use PHPUnit\Framework\TestCase;

/**
 * The fact-check report coerces a sloppy model response into a safe shape, and
 * the null checker reports unconfigured + throws.
 */
final class FactCheckReportTest extends TestCase
{
    public function testFromJsonCoercesClaims(): void
    {
        $r = FactCheckReport::fromJson([
            'summary' => 'Mostly accurate.',
            'claims' => [
                ['claim' => 'Price is $10', 'verdict' => 'contradicted', 'confidence' => 'high', 'note' => 'It is $12', 'sources' => ['https://a.example', '']],
                ['claim' => 'Founded 2020', 'verdict' => 'bogus'],
                'not-an-array',
            ],
        ], 'perplexity/sonar');

        self::assertSame('Mostly accurate.', $r->summary);
        self::assertSame('perplexity/sonar', $r->model);
        self::assertCount(2, $r->claims, 'the non-array claim is dropped');
        self::assertSame('contradicted', $r->claims[0]['verdict']);
        self::assertSame(['https://a.example'], $r->claims[0]['sources'], 'empty sources stripped');
        self::assertSame('unverified', $r->claims[1]['verdict'], 'invalid verdict → unverified');
        self::assertSame([], $r->claims[1]['sources']);
    }

    public function testFromJsonHandlesMissingFields(): void
    {
        $r = FactCheckReport::fromJson([], 'm');
        self::assertSame('', $r->summary);
        self::assertSame([], $r->claims);
    }

    public function testNullCheckerIsUnconfiguredAndThrows(): void
    {
        $c = new NullFactChecker();
        self::assertFalse($c->isConfigured());
        $this->expectException(FactCheckException::class);
        $c->check('some text');
    }
}
