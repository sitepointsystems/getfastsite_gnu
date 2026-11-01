<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\HookInjector;
use Fastsite\Component\Dto\ComponentHook;
use PHPUnit\Framework\TestCase;

final class HookInjectorTest extends TestCase
{
    private const BODY = '<p>one</p><p>two</p><p>three</p><p>four</p>';

    /**
     * @param array<string, mixed> $values
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private static function hook(
        string $position,
        int $paragraph = 1,
        array $values = [],
        int $id = 1,
        string $label = '',
        string $target = 'posts',
        array $include = [],
        array $exclude = [],
        int $priority = 100,
        string $component = 'cta',
    ): ComponentHook {
        return new ComponentHook($id, $label, $component, $values, $position, $paragraph, $target, $include, $exclude, $priority, true);
    }

    public function testNoHooksReturnsBodyUnchanged(): void
    {
        self::assertSame(self::BODY, HookInjector::inject(self::BODY, [], 'en'));
    }

    public function testBottomAppendsAtEnd(): void
    {
        $out = HookInjector::inject(self::BODY, [self::hook('bottom')], 'en');

        self::assertStringStartsWith(self::BODY, $out);
        self::assertStringContainsString('data-fs-component="cta"', $out);
    }

    public function testTopInsertsBeforeFirstParagraph(): void
    {
        $out = HookInjector::inject(self::BODY, [self::hook('top')], 'en');

        self::assertStringEndsWith(self::BODY, $out);
        self::assertStringStartsWith('<div data-fs-hook="1"', $out);
    }

    public function testAfterParagraphInsertsAtExactBoundary(): void
    {
        $out = HookInjector::inject(self::BODY, [self::hook('after_paragraph', 2)], 'en');

        self::assertStringContainsString('<p>two</p><div data-fs-hook="1"', $out);
        self::assertStringContainsString('</div><p>three</p>', $out);
    }

    public function testAfterParagraphClampsToBottomWhenPageIsShorter(): void
    {
        $out = HookInjector::inject('<p>only</p>', [self::hook('after_paragraph', 9)], 'en');

        self::assertStringStartsWith('<p>only</p><div data-fs-hook="1"', $out);
    }

    public function testMiddleLandsAfterHalfTheParagraphs(): void
    {
        // 4 paragraphs → after the 2nd.
        $out = HookInjector::inject(self::BODY, [self::hook('middle')], 'en');
        self::assertStringContainsString('<p>two</p><div data-fs-hook="1"', $out);

        // 5 paragraphs → after the 3rd.
        $out = HookInjector::inject(self::BODY . '<p>five</p>', [self::hook('middle')], 'en');
        self::assertStringContainsString('<p>three</p><div data-fs-hook="1"', $out);
    }

    public function testNestedParagraphsDoNotCount(): void
    {
        // The blockquote's inner </p> must not anchor "after paragraph 1" —
        // a CTA landing inside a quote would be visually broken.
        $body = '<blockquote><p>quoted</p></blockquote><p>real one</p><p>real two</p>';
        $out = HookInjector::inject($body, [self::hook('after_paragraph', 1)], 'en');

        self::assertStringContainsString('<p>real one</p><div data-fs-hook="1"', $out);
        self::assertStringNotContainsString('<p>quoted</p><div', $out);
    }

    public function testRawContentIsNeverParsed(): void
    {
        // A "</p>" inside a script string is text, not a paragraph boundary.
        $body = '<div data-fs-embed><script>var a = "</p>";</script><p>x</p></div><p>one</p><p>two</p>';
        $out = HookInjector::inject($body, [self::hook('after_paragraph', 1)], 'en');

        self::assertStringContainsString('<p>one</p><div data-fs-hook="1"', $out);
    }

    public function testVerbatimWrappedBodyIsEnteredNotBrokenOut(): void
    {
        // A trusted-port body: one data-fs-embed wrapper around everything.
        // Positions anchor on its direct paragraphs; bottom stays INSIDE it.
        $body = '<div data-fs-embed class="w"><p>one</p><p>two</p></div>';

        $afterFirst = HookInjector::inject($body, [self::hook('after_paragraph', 1)], 'en');
        self::assertStringContainsString('<p>one</p><div data-fs-hook="1"', $afterFirst);

        $bottom = HookInjector::inject($body, [self::hook('bottom')], 'en');
        self::assertStringEndsWith('</div></div>', $bottom);
        self::assertStringContainsString('</div></div></div>', $bottom); // hook's two divs + wrapper close

        $top = HookInjector::inject($body, [self::hook('top')], 'en');
        self::assertStringContainsString('class="w"><div data-fs-hook="1"', $top);
    }

    public function testMultipleHooksSortByOffsetAndKeepPriorityOrderAtSameSpot(): void
    {
        $out = HookInjector::inject(self::BODY, [
            self::hook('bottom', id: 1),
            self::hook('bottom', id: 2),
            self::hook('top', id: 3),
        ], 'en');

        $top = strpos($out, 'data-fs-hook="3"');
        $first = strpos($out, 'data-fs-hook="1"');
        $second = strpos($out, 'data-fs-hook="2"');
        self::assertNotFalse($top);
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($first, $top);
        self::assertLessThan($second, $first);
    }

    public function testLocaleIsPassedIntoValuesUnlessSet(): void
    {
        $out = HookInjector::inject('<p>x</p>', [self::hook('bottom', values: ['heading' => 'Hi'])], 'da');
        self::assertStringContainsString(htmlspecialchars('{"heading":"Hi","locale":"da"}', ENT_QUOTES), $out);

        $out = HookInjector::inject('<p>x</p>', [self::hook('bottom', values: ['locale' => 'xx'])], 'da');
        self::assertStringContainsString(htmlspecialchars('{"locale":"xx"}', ENT_QUOTES), $out);
    }

    public function testPlaceholderMatchesComponentRendererShape(): void
    {
        // The exact regex ComponentRenderer::PLACEHOLDER expands — an injected
        // placeholder that fails it would ship raw to the page.
        $out = HookInjector::inject('<p>x</p>', [self::hook('bottom', values: ['a' => '<b>&"'])], 'en');

        self::assertSame(1, preg_match('/<div\b[^>]*\bdata-fs-component="([a-z0-9-]+)"[^>]*><\/div>/i', $out, $m));
        self::assertSame('cta', $m[1]);
    }

    public function testWrapperCarriesCtaLocationFromLabel(): void
    {
        $out = HookInjector::inject('<p>x</p>', [self::hook('bottom', label: 'Newsletter CTA')], 'en');
        self::assertStringContainsString('data-cta-location="newsletter_cta"', $out);

        $out = HookInjector::inject('<p>x</p>', [self::hook('after_paragraph', 2)], 'en');
        self::assertStringContainsString('data-cta-location="hook_after_paragraph_2"', $out);
    }
}
