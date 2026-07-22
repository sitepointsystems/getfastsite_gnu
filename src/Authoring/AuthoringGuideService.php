<?php

declare(strict_types=1);

namespace Fastsite\Authoring;

use Fastsite\Site\Dto\Site;

/**
 * THE authoring contract every agent fetches before designing a site shell
 * or sending content. It is the single source of truth (this class) that
 * REST, MCP, and the dashboard all render, so the rules never drift.
 *
 * Content arrives BODY-ONLY (API/MCP/webhook); the shell (header/footer/
 * layout/theme) is designed separately. Following this guide is what keeps
 * every published page at 100/100/100/100 PageSpeed and SEO/a11y-clean.
 */
final class AuthoringGuideService
{
    /**
     * The rule set. Each rule: id, category, title, requirement, "how the
     * engine helps / what you must do", and an optional reference URL.
     *
     * @return list<array{id: string, category: string, title: string, requirement: string, applies: string, ref: string}>
     */
    public function rules(): array
    {
        return [
            // ── Content model ────────────────────────────────────────────
            $this->rule('content-body-only', 'Content model', 'Send body HTML only',
                'Posts and pages are pure HTML in the body. Do NOT include <html>, <head>, <header>, <footer>, <script>, <style>, or a page <title> in content — those come from the shell.',
                'The engine wraps your body in the shell and strips disallowed tags. Design the chrome once in the shell; author content as body fragments.'),
            $this->rule('content-images', 'Content model', 'Reference images by media id',
                'Embed images with <img data-media-id="N" alt="…">. Never hotlink external image URLs in the body.',
                'Upload via the media API/MCP first; the build expands data-media-id into a responsive <picture> with AVIF/WebP + explicit width/height. External <img src> FAILS the build.'),
            $this->rule('content-components', 'Content model', 'Embed components as placeholders',
                'Embed a component with <div data-fs-component="handle" data-values=\'{…}\'></div>.',
                'The build renders the component to static HTML (zero shipped JS). Only <div data-fs-component> + data-values survive sanitization.'),
            $this->rule('content-no-active', 'Content model', 'No scripts, styles, or external resources in the body',
                'No <script>, inline styles, iframes, or third-party <img>/<link>/<video src>.',
                'Sanitizer strips these; any external fetched resource or shipped <script> FAILS the build.'),

            // ── Shell requirements ──────────────────────────────────────
            $this->rule('shell-lang', 'Shell', 'Set <html lang> per locale',
                'The shell must emit <html lang="{{ site.locale }}"> so it is correct for every locale.',
                'The default base.html.twig does this. Confirm the site\'s PRIMARY LANGUAGE at setup — it drives <html lang> and the hreflang x-default.'),
            $this->rule('shell-hreflang', 'Shell', 'Emit hreflang alternates',
                'The <head> must loop {% for alt in page.hreflang %}<link rel="alternate" hreflang="…" href="…">{% endfor %} plus x-default.',
                'The engine provides page.hreflang per page. Missing it breaks multilingual SEO.'),
            $this->rule('shell-language-switcher', 'Shell', 'Place the language switcher in your nav',
                'On a multilingual site the language dropdown is NEVER inserted automatically — place it yourself: put `<!--fs:languages-->` or an empty `<li data-fs-languages></li>` where you want it (typically the header <nav>), or render your own switcher. The MCP tool `get_language_switcher` provides the markers, the rendered markup, and a customizable Twig variant.',
                'The engine replaces the marker in place with a zero-JS <details> dropdown of the built locales (flags + names, crawlable <a> links). With NO marker (and no data-fs-langnav switcher of your own) NOTHING is rendered — placement is always the designer\'s decision. You can instead render your own switcher by looping page.hreflang; keep data-fs-langnav on it so the language-persistence script binds.',
                'https://developers.google.com/search/docs/specialty/international/localized-versions'),
            $this->rule('shell-inline-css', 'Shell', 'Inline the CSS, ship zero JS',
                'Put all CSS inline via <style>{{ inline_css }}</style> in the <head>. Ship NO JavaScript.',
                'Inlined CSS is render-non-blocking and needs no extra request; the build FAILS on any <script> or external stylesheet. Keep inlined CSS under 60KB (WARN at 35KB).'),
            $this->rule('shell-landmarks', 'Shell', 'One <h1>, landmarks, and a skip link',
                'Exactly one <h1> per page; use <main id="main">, <header>, <footer>; provide a skip-link to #main.',
                'The default shell includes these. Multiple <h1> WARNs.'),
            $this->rule('shell-head', 'Shell', 'Complete <head>: title, description, canonical, OG',
                'Emit <title>, meta description, <link rel="canonical">, and Open Graph tags.',
                'The engine supplies page.title/seo_description/canonical. A missing title FAILs; a missing description WARNs.'),

            // ── Performance (Core Web Vitals) ───────────────────────────
            $this->rule('perf-cls', 'Performance', 'Explicit image dimensions (no layout shift)',
                'Every image needs width and height so nothing reflows as it loads (CLS).',
                'The media pipeline sets width/height automatically for data-media-id images; a raw <img> without them FAILS.',
                'https://developer.chrome.com/docs/lighthouse/performance/cumulative-layout-shift/'),
            $this->rule('perf-image-sizes', 'Performance', 'Upload images at their display size, not huge',
                'Match the image to its role so the site never ships oversized assets: a header/footer logo ~200–400px wide, an in-content or featured image ~1200–1600px wide (the OG card is auto-cropped to 1200×630), a favicon a square ≥512px.',
                'The engine caps the largest served variant at the site\'s "Max image width" setting (default 2048) and serves the logo at ~the "Logo width" setting — but uploading closer to the display size keeps files smallest. It never upscales.',
                'https://developer.chrome.com/docs/lighthouse/performance/uses-responsive-images/'),
            $this->rule('perf-lcp', 'Performance', 'Prioritize the LCP image',
                'The largest above-the-fold image should load eagerly with fetchpriority=high and a head preload.',
                'Set featured_media_id — the engine emits eager + fetchpriority=high + a preload for it.',
                'https://developer.chrome.com/docs/performance/insights/lcp-breakdown'),
            $this->rule('perf-render-blocking', 'Performance', 'No render-blocking resources',
                'No blocking external CSS/JS in the <head>.',
                'CSS is inlined; zero JS ships — so there is nothing to block rendering.',
                'https://developer.chrome.com/docs/performance/insights/render-blocking'),
            $this->rule('perf-no-legacy-js', 'Performance', 'No legacy / unused JavaScript',
                'Do not ship JS transpiled for old browsers, and ship no unused JS.',
                'The static output ships zero JS by default, so legacy/unused-JS audits pass trivially. Keep it that way.',
                'https://developer.chrome.com/docs/performance/insights/legacy-javascript'),
            $this->rule('perf-network-tree', 'Performance', 'Keep the network dependency tree shallow',
                'Avoid long chains of dependent requests.',
                'One HTML document + inlined CSS + same-origin media = a flat tree. Same-origin media served with immutable caching.',
                'https://developer.chrome.com/docs/performance/insights/network-dependency-tree'),
            $this->rule('perf-payload', 'Performance', 'Avoid enormous network payloads',
                'Keep each page small; do not paste huge inline data or oversized images.',
                'Images are optimized (ShortPixel + AVIF/WebP variants). A page over ~250KB of HTML WARNs.',
                'https://developer.chrome.com/docs/lighthouse/performance/total-byte-weight/'),
            $this->rule('perf-cache-gzip', 'Performance', 'Caching & compression are automatic',
                'You do not configure these.',
                'Cloudflare compresses (gzip/brotli) at the edge; the engine emits _headers with immutable caching for /assets, /media, /files. FCP/TTFB are strong because pages are static behind the CDN.'),

            // ── SEO ─────────────────────────────────────────────────────
            $this->rule('seo-apex', 'SEO', 'Canonical to the apex domain (no www.)',
                'Always link the apex host (example.com), never www.example.com, to avoid duplicate content.',
                'The engine normalizes the site base URL to the apex, canonicals to it, and emits a www→apex 301 redirect. A www. canonical WARNs.'),
            $this->rule('seo-crawlable-links', 'SEO', 'Use real, crawlable links',
                'Navigation must use <a href="/real/path">. No href-less <a>, bare href="#", or javascript: links.',
                'In-page anchors (href="#section") are fine. Non-crawlable links WARN.',
                'https://developers.google.com/search/docs/crawling-indexing/links-crawlable'),
            $this->rule('seo-auto-files', 'SEO', 'sitemap.xml, robots.txt, llms.txt are automatic',
                'Do not hand-author these — the build generates them every publish.',
                'Per-locale sitemaps + index, a robots.txt (with a banner + Sitemap + llms link), and an llms.txt describing your pages/posts for LLMs.'),

            // ── Accessibility ───────────────────────────────────────────
            $this->rule('a11y-contrast', 'Accessibility', 'Sufficient text contrast',
                'Foreground text must have ≥4.5:1 contrast with its background (≥3:1 for large text).',
                'Choose shell token colors that pass. Verify with Lighthouse; contrast cannot be checked from HTML alone.',
                'https://developer.chrome.com/docs/lighthouse/accessibility/color-contrast/'),
            $this->rule('a11y-link-color', 'Accessibility', 'Links must be distinguishable beyond color',
                'Links inside body text need an underline (or ≥3:1 contrast against surrounding text AND a non-color cue).',
                'The default shell underlines article-body links. Keep body links underlined.',
                'https://dequeuniversity.com/rules/axe/4.12/link-in-text-block'),
            $this->rule('a11y-alt', 'Accessibility', 'Every image needs alt text',
                'Provide meaningful alt text; mark purely decorative images decorative:true.',
                'alt is required at upload; a missing alt FAILS the build.'),
        ];
    }

    /**
     * The full guide for an agent: Markdown + the structured rules + (if a
     * site is given) that site's specifics.
     *
     * @return array{markdown: string, rules: list<array<string, string>>, site: array<string, mixed>|null}
     */
    public function guide(?Site $site = null): array
    {
        return [
            'markdown' => $this->markdown($site),
            'rules' => $this->rules(),
            'site' => $site !== null ? [
                'handle' => $site->handle,
                'name' => $site->name,
                'base_url' => $site->baseUrl,
                'primary_locale' => $site->primaryLocale,
                'locales' => $site->locales,
            ] : null,
        ];
    }

    private function markdown(?Site $site): string
    {
        $md = "# fastsite Authoring Guide\n\n";
        $md .= "Read this before designing a site shell or sending content. Content arrives **body-only** via API/MCP/webhook; the shell (header/footer/layout/theme) is designed separately. Following these rules keeps every page at **100/100/100/100** PageSpeed and SEO/accessibility-clean.\n\n";

        if ($site !== null) {
            $locales = $site->locales === [] ? '(none — single language)' : implode(', ', $site->locales);
            $md .= "## This site\n\n";
            $md .= "- **Handle:** {$site->handle}\n";
            $md .= "- **Name:** {$site->name}\n";
            $md .= '- **Primary language:** ' . $site->primaryLocale . " — this drives `<html lang>` and the hreflang `x-default`. Confirm it is correct.\n";
            $md .= "- **Additional locales:** {$locales}\n";
            $md .= '- **Base URL:** ' . ($site->baseUrl !== '' ? $site->baseUrl : '(not set)') . " (apex — never link the www. host)\n";
            $md .= "- **Embed an image:** `<img data-media-id=\"N\" alt=\"…\">` (upload it first)\n";
            $md .= "- **Embed a component:** `<div data-fs-component=\"handle\" data-values='{…}'></div>`\n";
            if ($site->locales !== []) {
                $md .= "- **Place the language switcher:** it is never inserted automatically — put `<!--fs:languages-->` (or `<li data-fs-languages></li>`) where you want it and the engine renders a zero-JS dropdown there. `get_language_switcher` returns the markers, the rendered markup, and a customizable Twig variant.\n";
            }
            $md .= "\n";
        } else {
            $md .= "## Confirm the primary language\n\nBefore anything else, decide the site's **primary language** — it sets `<html lang>` and the hreflang `x-default`, and cannot be guessed from content.\n\n";
        }

        $byCategory = [];
        foreach ($this->rules() as $rule) {
            $byCategory[$rule['category']][] = $rule;
        }
        foreach ($byCategory as $category => $rules) {
            $md .= "## {$category}\n\n";
            foreach ($rules as $rule) {
                $md .= "### {$rule['title']}\n\n";
                $md .= "- **Requirement:** {$rule['requirement']}\n";
                $md .= "- **How the engine helps / what you do:** {$rule['applies']}\n";
                if ($rule['ref'] !== '') {
                    $md .= "- **Reference:** {$rule['ref']}\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /**
     * @return array{id: string, category: string, title: string, requirement: string, applies: string, ref: string}
     */
    private function rule(string $id, string $category, string $title, string $requirement, string $applies, string $ref = ''): array
    {
        return compact('id', 'category', 'title', 'requirement', 'applies', 'ref');
    }
}
