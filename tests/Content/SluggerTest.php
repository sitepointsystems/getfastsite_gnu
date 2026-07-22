<?php

declare(strict_types=1);

namespace Fastsite\Tests\Content;

use Fastsite\Content\Service\Slugger;
use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    public function testBasics(): void
    {
        self::assertSame('hello-fastsite', Slugger::slugify('Hello fastsite'));
        self::assertSame('a-b-c', Slugger::slugify('  a  b  c  '));
        self::assertSame('untitled', Slugger::slugify('***'));
    }

    public function testDanishTransliteration(): void
    {
        self::assertSame('blaa-baer-groed', Slugger::slugify('Blå bær grød'));
        self::assertSame('oester', Slugger::slugify('Øster'));
    }

    public function testLengthCap(): void
    {
        $slug = Slugger::slugify(str_repeat('word ', 60));
        self::assertLessThanOrEqual(120, \strlen($slug));
    }

    public function testSlugifyPathPreservesNesting(): void
    {
        self::assertSame('help/getting-started', Slugger::slugifyPath('help/getting-started'));
        self::assertSame('help/getting-started', Slugger::slugifyPath('Help/Getting Started'));
    }

    public function testSlugifyPathCollapsesAndIsTraversalSafe(): void
    {
        self::assertSame('help/x', Slugger::slugifyPath('/help//x/'));
        self::assertSame('a/b', Slugger::slugifyPath('a/../b'));   // ".." dropped, no traversal
        self::assertSame('untitled', Slugger::slugifyPath('..//..'));
        self::assertSame('features', Slugger::slugifyPath('features')); // single segment == slugify
    }
}
