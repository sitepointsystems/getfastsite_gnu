<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\TocBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Heading-id injection + TOC extraction contract: slugified ids on h2/h3
 * lacking one, hand-written ids respected, collisions deduped, and the
 * whole thing a no-op on heading-less bodies.
 */
final class TocBuilderTest extends TestCase
{
    public function testInjectsSlugifiedIdsAndExtractsToc(): void
    {
        $r = TocBuilder::build('<h2>First Section</h2><p>x</p><h3>Sub Point</h3><h2>Second Section</h2>');

        self::assertStringContainsString('<h2 id="first-section">First Section</h2>', $r['html']);
        self::assertStringContainsString('<h3 id="sub-point">Sub Point</h3>', $r['html']);
        self::assertSame([
            ['id' => 'first-section', 'text' => 'First Section', 'level' => 2],
            ['id' => 'sub-point', 'text' => 'Sub Point', 'level' => 3],
            ['id' => 'second-section', 'text' => 'Second Section', 'level' => 2],
        ], $r['toc']);
    }

    public function testDanishCharactersFollowTheSiteSlugConventions(): void
    {
        $r = TocBuilder::build('<h2>Særlige forhold på øen</h2>');

        self::assertStringContainsString('id="saerlige-forhold-paa-oeen"', $r['html']);
        self::assertSame('Særlige forhold på øen', $r['toc'][0]['text']);
    }

    public function testHandWrittenIdIsRespectedAndListed(): void
    {
        $r = TocBuilder::build('<h2 id="custom-anchor" class="x">My Heading</h2>');

        self::assertStringContainsString('<h2 id="custom-anchor" class="x">My Heading</h2>', $r['html']);
        self::assertSame('custom-anchor', $r['toc'][0]['id']);
    }

    public function testDuplicateHeadingsGetSuffixedIds(): void
    {
        $r = TocBuilder::build('<h2>Setup</h2><h2>Setup</h2><h2>Setup</h2>');

        self::assertSame(['setup', 'setup-2', 'setup-3'], array_column($r['toc'], 'id'));
    }

    public function testGeneratedIdNeverCollidesWithAnExistingBodyId(): void
    {
        // A div later in the document already claims the natural slug.
        $r = TocBuilder::build('<h2>Notes</h2><div id="notes">aside</div>');

        self::assertStringContainsString('<h2 id="notes-2">Notes</h2>', $r['html']);
        self::assertSame('notes-2', $r['toc'][0]['id']);
    }

    public function testAttributesAndInlineMarkupSurvive(): void
    {
        $r = TocBuilder::build('<h2 class="fancy" data-x="a>b">Use <code>foo()</code> &amp; bar</h2>');

        self::assertStringContainsString('<h2 class="fancy" data-x="a>b" id="use-foo-bar">Use <code>foo()</code> &amp; bar</h2>', $r['html']);
        self::assertSame('Use foo() & bar', $r['toc'][0]['text']);
    }

    public function testNoHeadingsIsANoOp(): void
    {
        $html = '<p>Nothing to anchor.</p>';
        $r = TocBuilder::build($html);

        self::assertSame($html, $r['html']);
        self::assertSame([], $r['toc']);
    }

    public function testEmptyHeadingIsSkipped(): void
    {
        $r = TocBuilder::build('<h2> </h2><h2>Real</h2>');

        self::assertCount(1, $r['toc']);
        self::assertSame('real', $r['toc'][0]['id']);
    }

    public function testH1AndH4AreIgnored(): void
    {
        $r = TocBuilder::build('<h1>Title</h1><h4>Deep</h4><h2>Section</h2>');

        self::assertStringNotContainsString('<h1 id=', $r['html']);
        self::assertStringNotContainsString('<h4 id=', $r['html']);
        self::assertSame(['section'], array_column($r['toc'], 'id'));
    }
}
