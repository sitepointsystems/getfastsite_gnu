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
            $this->rule('content-structured-fields', 'Content model', 'Use intro / tldr / conclusion for summary boxes',
                'A post\'s lead paragraph, TL;DR summary box, and conclusion are separate fields (`intro`, `tldr`, `conclusion` — each an HTML fragment, e.g. one <p> or a <ul>). Do NOT bake their markup into body_html — the shell renders them as styled boxes so every post shares one design.',
                'Send them on create_post/update_post (MCP), the content REST endpoints, or webhook payloads. They are sanitized and auto-translated like the body, and exposed to the shell as content.intro_html / content.tldr_html / content.conclusion_html. Empty string clears a field on update.'),
            $this->rule('content-toc', 'Content model', 'Table of contents is automatic + opt-in',
                'The build injects slugified id attributes into body <h2>/<h3> that lack one (hand-written ids are respected) and exposes `content.toc` (list of {id, text, level}) to the shell. A shell opts into a rendered TOC by emitting a nav that carries the `data-fs-toc` attribute with `#id` links.',
                'When a page renders a data-fs-toc nav, the engine injects a tiny scroll-spy that marks the active link with class `fs-active` + aria-current="true" (style those in shell CSS) and the site\'s CSP allows inline scripts (Lighthouse stays 100). No nav → no script ships and CSP stays zero-JS.'),
            $this->rule('content-images', 'Content model', 'Reference images by media id',
                'Embed images with <img data-media-id="N" alt="…">. Never hotlink external image URLs in the body.',
                'Upload via the media API/MCP first; the build expands data-media-id into a responsive <picture> with AVIF/WebP + explicit width/height. External <img src> FAILS the build.'),
            $this->rule('content-upload-route', 'Content model', 'Upload a file you hold with an upload ticket',
                'To get a file you are HOLDING into the library, call create_upload_ticket and PUT the raw bytes to the upload_url it returns: curl --upload-file photo.jpg "<upload_url>". Use upload_media_from_url when the file is already hosted. Treat upload_media_from_base64 as a last resort for something small.',
                'An upload ticket is single-use, expires in 30 minutes and is scoped to one site, and the bytes travel as an ordinary HTTP body — so the only limits that apply are the real per-type ceilings (15 MB image, 100 MB generic file, 500 MB video). Base64 is different in kind, not just degree: it is an ARGUMENT of the tool call, so a model has to emit it at about 4/3 the size of the file, which exhausts an output budget around 100 KB and then TRUNCATES rather than erroring — the item still reports status: ready while holding a corrupt file. If you cannot make an HTTP request at all, say so rather than sending a large base64 payload.'),
            $this->rule('content-components', 'Content model', 'Embed components as placeholders',
                'Embed a component with <div data-fs-component="handle" data-values=\'{…}\'></div>.',
                'The build renders the component to static HTML (zero shipped JS). Only <div data-fs-component> + data-values survive sanitization.'),
            $this->rule('content-forms', 'Content model', 'Embed forms as placeholders — never write form markup',
                'A contact or enquiry form is embedded with <div data-fs-form="handle"></div>. Do NOT author <form>, <input>, <textarea> or <button> in body HTML: the sanitizer strips them on every ingest path, so hand-written form markup silently disappears.',
                'Define the form first (its fields, recipients and subject) on the dashboard Forms screen, via `upsert_form` over MCP, or PUT /api/v1/sites/{site}/forms/{handle}. The build then generates a real, accessible, natively-validating form in place of the placeholder — and it ships ZERO JavaScript, so the page keeps its 100/100/100/100. The recipient address lives in the form definition and can never be set from the page. If the handle does not exist the placeholder renders as nothing, so create the form before referencing it.'),
            $this->rule('content-forms-custom', 'Content model', 'A bespoke form can post to the endpoint itself',
                'When the generated markup is not enough — a multi-step wizard, a price calculator, anything with its own layout or script — author the form yourself in VERBATIM content and point it at the same endpoint: `<form method="post" action="{engine}/f/{site}/{form-handle}">`. Still define the form in the engine: that is what supplies the recipients, the field labels and order in the notification, and the server-side validation.',
                'Give each input a `name` matching a field in the definition (undefined names are ignored, so nothing a bot invents is stored). Use `hidden` fields for values your script computes, such as a running total. Add the consent checkbox as `_fs_consent` if the definition sets consent text. Do NOT render the honeypot — it only exists in generated markup. The endpoint answers a 303 to your thank-you page on success and a plain error page on a validation failure, so it works with no JavaScript at all.'),

            $this->rule('content-categories', 'Content model', 'Give every post its category; let the engine build archives and crumbs',
                'A post has ONE category (`category` on create_post/update_post, by slug — create it first with upsert_category). It decides the archive the post is listed in, the Home > Category > post breadcrumb, and, when the site\'s post_path_pattern carries {category}, the post\'s URL. Pages have no category. Never hand-write archive pages or breadcrumb markup in content.',
                'The build generates every category archive (/category/{slug}/, then /category/{slug}/page/2/ …) from the posts, each canonical to itself, and gives the shell `page.breadcrumb`, `category` and `pagination` to render (template `category.html.twig`, falling back to blog-index). To keep a ported site\'s URLs exactly, set post_path_pattern (e.g. /{category}/{slug}/) and category_path_pattern BEFORE importing; changing them later records a 301 for every moved URL.'),

            $this->rule('content-no-active', 'Content model', 'No scripts, styles, or external resources in the body',
                'No <script>, inline styles, iframes, or third-party <img>/<link>/<video src>.',
                'Sanitizer strips these; any external fetched resource or shipped <script> FAILS the build. EXCEPTION — trusted ports: a site with sanitize_content off (or a single write passing sanitize:false) stores the body VERBATIM, keeping scripts/styles/iframes and interactive markup and exempting the page from these checks. Verbatim is a property of the content, so translated copies keep it too.'),

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
                'Set featured_media_id (on create OR update; 0/null clears) — the engine renders that image eager + fetchpriority=high wherever it appears (featured_html or authored in the body) and preloads it in the head. Without it, the first hero-sized (≥1000px wide) body image is prioritised automatically; a page with images but no prioritised one WARNs at build.',
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
            $this->rule('seo-apex', 'SEO', 'Use the site\'s ONE canonical host',
                'Link only the site\'s canonical host — the apex (example.com) by default, or www.example.com if that is what the site chose. Never mix the two.',
                'Prefer root-relative links (/blog/x/) and the engine gets the host right for you. It canonicals every page to the chosen host; the other form 301s to it (performed by the engine in direct mode, by a Cloudflare zone Redirect Rule on a CDN). A canonical on the wrong host WARNs.'),
            $this->rule('seo-crawlable-links', 'SEO', 'Use real, crawlable links',
                'Navigation must use <a href="/real/path">. No href-less <a>, bare href="#", or javascript: links.',
                'In-page anchors (href="#section") are fine. Non-crawlable links WARN.',
                'https://developers.google.com/search/docs/crawling-indexing/links-crawlable'),
            $this->rule('seo-auto-files', 'SEO', 'sitemap.xml, robots.txt, llms.txt are automatic',
                'Do not hand-author these — the build generates them every publish.',
                'Per-locale sitemaps + index, a robots.txt (with a banner + Sitemap + llms link), and an llms.txt describing your pages/posts for LLMs.'),
            $this->rule('seo-structured-data', 'SEO', 'Structured data is automatic — do not hand-write JSON-LD',
                'Never put a <script type="application/ld+json"> block in a shell template or in body HTML. The build emits ONE schema.org @graph per page into the <head> and it already covers the site entity (Organization or Person, with logo and sameAs profiles), WebSite, WebPage (CollectionPage on the blog index), BreadcrumbList, and on posts a BlogPosting with its author, publisher and featured ImageObject — all cross-referenced by @id.',
                'It ships on EVERY site regardless of shell: the graph is injected into the rendered head, and a shell that emits the `json_ld` variable itself is detected so nothing is duplicated. Page-specific extras (Product, Event, HowTo, a Recipe) go in the content row\'s `json_ld` field — those nodes are merged INTO the page graph, and a node declaring its own Article/BlogPosting type replaces the engine\'s. Set the site\'s schema_type to Person for a personal brand, and fill in schema_same_as with real profile URLs: that is what actually resolves the site to an entity. Deliberately absent, and not worth adding by hand: SearchAction/sitelinks searchbox (retired by Google in November 2024) and FAQPage (rich results withdrawn in August 2023).',
                'https://developers.google.com/search/docs/appearance/structured-data/search-gallery'),
            $this->rule('seo-share-card', 'SEO', 'A page featured image is also its share card',
                'Set a featured image on every post and page you expect to be shared. The build turns it into that page own og:image and twitter:image automatically — a 1200x630 crop the media pipeline already renders for every upload, served as an absolute URL because scrapers do not resolve relative paths. A page with no featured image falls back to the site default share image, so a site whose pages all share one card is a site whose pages have no featured images.',
                'A shell reads `page.og_image_url` / `_width` / `_height` / `_alt` and falls back to `site.social_image_url` / `social_image_*` when the page carries none; take width, height and alt from whichever one you used, never a mix. Both bundled shells do this. The crop is only published for images some page actually references as a card, so do not hand-build a URL to one.',
                'https://developers.facebook.com/docs/sharing/webmasters/images'),

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
                'Omitted alt is generated automatically at upload (vision AI when the site has an AI key, else the filename) — but an alt you write beats a generated one; a missing alt in shipped HTML FAILS the build.'),
            $this->rule('seo-lengths', 'SEO', 'Titles and descriptions fit the snippet',
                'SEO titles 30–60 characters; meta descriptions 120–156. Google truncates beyond that and a shorter one wastes the snippet.',
                'Check with the seo:check CLI / check_seo_lengths MCP tool; fix_seo_lengths rewrites offenders to fit.',
                'https://developers.google.com/search/docs/appearance/snippet'),

            // ── Analytics ───────────────────────────────────────────────
            $this->rule('analytics-cta-attributes', 'Analytics', 'Every link and button carries CTA tracking attributes',
                'Every <a href>, <button> and submit <input> on a published page ships four inert data attributes for GTM click tracking: data-cta (stable snake_case id, IDENTICAL on every locale\'s variant of the page), data-cta-text (the actual visible text — per-locale), data-cta-language (the page\'s locale) and data-cta-location (header/navigation/footer/hero/…).',
                'The build fills anything you omit (id from the href path with the locale prefix stripped, text from the element, location from the nearest landmark), so plain markup is already compliant. Author data-cta explicitly on your IMPORTANT conversion CTAs ("book_demo", "signup") so reports use your naming — an authored attribute always beats the generated one. Off switch: the site\'s cta_tracking setting. Zero JS ships either way; GTM\'s own click triggers read the attributes.'),
            $this->rule('analytics-cta-location', 'Analytics', 'Mark regions once with data-cta-location',
                'data-cta-location cascades: put it on a CONTAINER and every link/button inside inherits it. Unmarked regions fall back to landmarks — header → header, nav → navigation, footer → footer, aside → sidebar — with "content" as the default.',
                'In the shell, mark the regions landmarks cannot name: <section data-cta-location="hero">, a pricing block, a sticky CTA bar. One attribute per region beats per-link annotation, and it survives every content change.'),
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
                'base_url' => $site->canonicalBaseUrl(),
                'canonical_host' => $site->canonicalHost,
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
            $md .= '- **Base URL:** ' . ($site->canonicalBaseUrl() !== '' ? $site->canonicalBaseUrl() : '(not set)')
                . ' — this site\'s canonical host is **' . ($site->canonicalHost === 'www' ? 'www' : 'the apex (no www.)') . "**. Never link the other host form; it only exists to 301 here.\n";
            $md .= "- **Embed an image:** `<img data-media-id=\"N\" alt=\"…\">` (upload it first)\n";
            $md .= "- **Embed a component:** `<div data-fs-component=\"handle\" data-values='{…}'></div>`\n";
            $md .= "- **Embed a form:** `<div data-fs-form=\"handle\"></div>` — define the form first (`upsert_form`); never author `<form>`/`<input>` markup, the sanitizer strips it.\n";
            $md .= "- **Structured fields:** send `intro`, `tldr`, `conclusion` (HTML fragments) alongside `body_html` — the shell renders them as styled boxes; never bake them into the body.\n";
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
