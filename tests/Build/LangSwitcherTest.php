<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\LangSwitcher;
use PHPUnit\Framework\TestCase;

/**
 * The switcher markup contract — this exact string is what replaces a
 * placement marker at build AND what the MCP get_language_switcher tool
 * returns as the rendered example, so both consumers pin it here.
 */
final class LangSwitcherTest extends TestCase
{
    public function testRendersAZeroJsDropdownWithCrawlableLinks(): void
    {
        $html = LangSwitcher::render(['en' => '/blog/x/', 'da' => '/da/blog/x/'], 'en');

        self::assertStringStartsWith('<details data-fs-langnav', $html);
        self::assertStringContainsString('<summary', $html);
        self::assertStringNotContainsString('<script', $html, 'the switcher itself ships zero JS');

        // Crawlable per-locale links with hreflang; current locale marked.
        self::assertStringContainsString('href="/blog/x/" hreflang="en" aria-current="true"', $html);
        self::assertStringContainsString('href="/da/blog/x/" hreflang="da"', $html);
        self::assertStringNotContainsString('hreflang="da" aria-current', $html);

        // Human language names, not codes.
        self::assertStringContainsString('English', $html);
        self::assertStringContainsString('Danish', $html);
    }

    public function testTriggerInheritsTheHostNavsColorAndTypography(): void
    {
        $html = LangSwitcher::render(['en' => '/', 'da' => '/da/'], 'en');

        // The widget lands inside someone's nav: hardcoding a text color or a
        // font stack made it collide with light shells (white-on-dark pill
        // next to a Bulma navbar). Inheritance is the contract now.
        self::assertStringNotContainsString('#fff', $html, 'no hardcoded text color');
        self::assertStringNotContainsString('system-ui', $html, 'no hardcoded font stack');
        self::assertStringNotContainsString('rgba(15,20,28', $html, 'no hardcoded dark pill');

        // The floating panel is the one exception — an overlay cannot inherit
        // safely. It is themeable via cascading custom properties (a shell
        // with dark-green dropdowns sets --fs-lang-bg once), falling back to
        // the UA's native-control surface colors.
        self::assertStringContainsString('background:var(--fs-lang-bg,Canvas)', $html);
        self::assertStringContainsString('color:var(--fs-lang-fg,CanvasText)', $html);
        self::assertStringContainsString('border:1px solid var(--fs-lang-border,', $html);
    }

    public function testEveryVisualDefaultIsOverridableBySiteCss(): void
    {
        $html = LangSwitcher::render(['en' => '/', 'da' => '/da/'], 'en');

        // No inline design at all: an inline style attribute beats any site
        // stylesheet without !important, so none may exist.
        self::assertStringNotContainsString(' style="', $html, 'no inline style attributes');

        // Visual defaults (incl. hover + current-item tints) sit at ZERO
        // specificity via :where(), so a shell's plain `.fs-lp a:hover{…}` —
        // or even a bare `a:hover` — wins regardless of source order. Both
        // tints share the --fs-lang-tint token for var-based theming.
        self::assertStringContainsString(':where(.fs-lp a:hover){background:var(--fs-lang-tint,', $html);
        self::assertStringContainsString(':where(.fs-lp a[aria-current]){background:var(--fs-lang-tint,', $html);
        self::assertStringContainsString(':where(details[data-fs-langnav]>summary)', $html);

        // Mechanics stay protected at normal specificity — a stray site reset
        // must not silently break the collapse.
        self::assertStringContainsString('details[data-fs-langnav]>.fs-lp{display:none;position:absolute}', $html);
        self::assertStringContainsString('details[data-fs-langnav][open]>.fs-lp{display:flex}', $html);
    }

    public function testFlagsRenderOnlyWhenProvided(): void
    {
        $bare = LangSwitcher::render(['en' => '/', 'da' => '/da/'], 'en');
        self::assertStringNotContainsString('<img', $bare);

        $flagged = LangSwitcher::render(['en' => '/', 'da' => '/da/'], 'en', ['da' => '/flags/dk.svg']);
        self::assertStringContainsString('src="/flags/dk.svg"', $flagged);
    }

    public function testHrefsAndLocalesAreEscaped(): void
    {
        $html = LangSwitcher::render(['en' => '/x/?a="b"'], 'en');
        self::assertStringNotContainsString('?a="b"', $html);
        self::assertStringContainsString('&quot;b&quot;', $html);
    }
}
