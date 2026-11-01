<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

/**
 * The engine's language-switcher widget: a zero-JS native <details> dropdown
 * (flags + language names, crawlable <a hreflang> links).
 *
 * Styling contract: NOTHING visual is hardcoded against the site.
 * - The trigger sets no color, font or background — it inherits the
 *   surrounding nav's typography and currentColor.
 * - Every visual default (spacing, radius, shadow, panel surface, hover and
 *   current-item tints) lives in the widget's <style> block wrapped in
 *   `:where()`, which has ZERO specificity — so any real selector in the
 *   shell's CSS (even a bare `a:hover`) overrides it, no !important needed.
 *   There are no inline style attributes at all.
 * - Quick theming without writing selectors: the --fs-lang-bg / --fs-lang-fg /
 *   --fs-lang-border / --fs-lang-tint custom properties cascade in from
 *   anywhere (e.g. :root in tokens.css). Unthemed, the panel falls back to the
 *   UA's native-control surface (Canvas/CanvasText, like a <select> popup) —
 *   an overlay cannot safely inherit, so that is the one self-styled default.
 * - Only MECHANICS keep normal specificity (positioning context, the
 *   open/close display toggle, the summary marker) so a stray site reset
 *   can't silently break the dropdown's function.
 *
 * Extracted from BuildService so the MCP `get_language_switcher` tool can hand
 * agents the LITERAL markup the build renders — one source of truth for both.
 *
 * The `data-fs-langnav` attribute is load-bearing: the build's language-
 * persistence script binds to it, and its presence tells the build the page
 * already has a switcher.
 */
final class LangSwitcher
{
    /**
     * @param array<string, string> $links locale => href, current page's built variants, primary first
     * @param array<string, string> $flagUrls locale → '/flags/{cc}.svg' for locales whose flag exists in dist
     * @param bool $ctaAttrs emit the GTM CTA data attributes on every panel
     *   link. The switcher is injected AFTER the CtaAnnotator build pass, so
     *   it must carry its own; the id is locale-derived ("lang_da") so it is
     *   identical on every page and every language.
     */
    public static function render(array $links, string $current, array $flagUrls = [], bool $ctaAttrs = false): string
    {
        // Flag <img> for a locale (real SVG copied into dist/flags/), or ''.
        // width/height stay as attributes (CLS contract, not design).
        $flagImg = static fn (string $loc, int $w = 18, int $h = 13): string => isset($flagUrls[$loc])
            ? '<img src="' . htmlspecialchars($flagUrls[$loc], ENT_QUOTES) . '" alt="" width="' . $w . '" height="' . $h . '">'
            : '';
        $label = static fn (string $loc): string => htmlspecialchars(\Fastsite\Shared\Locales::name($loc), ENT_QUOTES);

        // Panel links: one per built locale (flag + language name). Real
        // <a href> so they stay crawlable and get relativized in preview.
        // No style attributes — appearance comes from the defaults below.
        $items = '';
        foreach ($links as $loc => $href) {
            $cta = $ctaAttrs
                ? ' data-cta="lang_' . htmlspecialchars(str_replace('-', '_', $loc), ENT_QUOTES) . '"'
                    . ' data-cta-text="' . $label($loc) . '"'
                    . ' data-cta-language="' . htmlspecialchars($current, ENT_QUOTES) . '"'
                    . ' data-cta-location="language_switcher"'
                : '';
            $items .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" hreflang="' . htmlspecialchars($loc, ENT_QUOTES) . '"'
                . ($loc === $current ? ' aria-current="true"' : '')
                . $cta
                . '>' . $flagImg($loc) . '<span>' . $label($loc) . '</span></a>';
        }

        $summary = '<summary aria-label="Language">'
            . $flagImg($current) . '<span>' . $label($current) . '</span>'
            . '<span class="fs-lc" aria-hidden="true">&#9662;</span></summary>';
        $panel = '<div class="fs-lp">' . $items . '</div>';

        // Mechanics at normal specificity: the details is the positioning
        // context for the absolute panel, the panel only displays in the
        // [open] state (driving display explicitly is what collapses it), and
        // the UA's summary marker is hidden.
        $css = 'details[data-fs-langnav]{position:relative}'
            . 'details[data-fs-langnav]>summary::-webkit-details-marker{display:none}'
            . 'details[data-fs-langnav]>.fs-lp{display:none;position:absolute}'
            . 'details[data-fs-langnav][open]>.fs-lp{display:flex}'
            // Design defaults at ZERO specificity via :where() — the shell's
            // own CSS wins with any plain selector. Hover comes before
            // aria-current so the current item keeps its tint while hovered;
            // both share --fs-lang-tint with distinct unthemed fallbacks.
            . ':where(details[data-fs-langnav]){display:inline-block}'
            . ':where(details[data-fs-langnav]>summary){list-style:none;cursor:pointer;display:flex;align-items:center;gap:.4rem;padding:.3rem .5rem;border-radius:999px}'
            . ':where(details[data-fs-langnav] img){border-radius:2px;object-fit:cover;display:block}'
            . ':where(.fs-lc){opacity:.7}'
            . ':where(.fs-lp){top:calc(100% + .3rem);right:0;min-width:9rem;flex-direction:column;gap:.1rem;'
            . 'background:var(--fs-lang-bg,Canvas);color:var(--fs-lang-fg,CanvasText);padding:.3rem;border-radius:10px;'
            . 'border:1px solid var(--fs-lang-border,rgba(127,127,127,.35));box-shadow:0 12px 30px rgba(0,0,0,.25)}'
            . ':where(.fs-lp a){display:flex;align-items:center;gap:.4rem;color:inherit;text-decoration:none;padding:.35rem .55rem;border-radius:7px;white-space:nowrap}'
            . ':where(.fs-lp a:hover){background:var(--fs-lang-tint,rgba(127,127,127,.14))}'
            . ':where(.fs-lp a[aria-current]){background:var(--fs-lang-tint,rgba(127,127,127,.22))}';

        return '<details data-fs-langnav>' . $summary . $panel . '<style>' . $css . '</style></details>';
    }
}
