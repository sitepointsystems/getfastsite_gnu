<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

/**
 * Audits generated HTML against the PageSpeed-100 contract. Two severities:
 *  - FAIL: a pipeline invariant was violated (missing img dimensions, an
 *    external render-blocking resource, shipped JS framework). These the
 *    pipeline guarantees, so a FAIL is a real bug — the build should stop.
 *  - WARN: content-borne smells (heading jumps) that must never block an
 *    unattended publish.
 *
 * Findings are collected across every emitted page; the caller decides what
 * to do with FAILs (the build job fails on any FAIL).
 */
final class PageAudit
{
    private const CSS_WARN_BYTES = 35 * 1024;
    private const CSS_FAIL_BYTES = 60 * 1024;
    // "Avoid enormous network payloads" — a single page's HTML (with its
    // inlined CSS) over this is a content smell, not a pipeline failure.
    private const PAGE_WARN_BYTES = 250 * 1024;

    /** @var list<array{severity: string, page: string, message: string}> */
    private array $findings = [];

    /**
     * @param bool $allowScripts the site opted into user scripts (#13) — the
     *   zero-JS FAIL and the external-<script src> FAIL are relaxed for it.
     * @param string $canonicalHost the one host every canonical must use
     *   (the site's apex, or its www. form when that is the canonical host).
     *   Empty = the site has no base URL, so the check is skipped.
     * @param list<string> $userScripts the verbatim blocks the build injects
     *   into every page for a scripts-enabled site (#13). Removed before the
     *   asset checks, exactly like a data-fs-embed region: the operator pasted
     *   this markup knowing it forfeits the guarantee, and a tag pack is more
     *   than a <script src> — GTM's own snippet carries a <noscript><iframe>,
     *   which as an "external resource" FAILed every page and made the feature
     *   unpublishable. The rest of each page is still audited normally.
     * @param bool $ctaTracking the site's CTA-tracking toggle is on, so the
     *   CtaAnnotator build pass ran — every link/button must now carry the
     *   four data-cta* attributes. An element without them is an annotator
     *   bug (the pass normalizes content, so content can never trip this),
     *   which makes it a pipeline-invariant FAIL like the img checks.
     * @param array{url?: string, type?: string, width?: int, height?: int} $favicon
     *   what the build resolved for this site's favicon, or [] when none is
     *   configured. Checked at SITE level rather than per page: the value is
     *   the same on every page, and one honest line beats the same warning
     *   repeated a few hundred times.
     */
    public function __construct(
        private readonly bool $allowScripts = false,
        private readonly string $canonicalHost = '',
        private readonly array $userScripts = [],
        private readonly bool $ctaTracking = false,
        private readonly bool $development = false,
        private readonly array $favicon = [],
    ) {
    }

    /**
     * Site-level findings — things that are true of the whole build rather than
     * of one page. Called once per build, alongside auditCss.
     */
    public function auditSite(): void
    {
        // Not a defect, but the single most expensive thing to discover late:
        // a site that has quietly been invisible to Google since launch. The
        // build log is where someone looks when traffic never arrives.
        if ($this->development) {
            $this->warn('(site)', 'Development mode is ON — every page ships noindex, nofollow and the site cannot appear in search. Turn it off on Site Settings to go live.');
        }

        // The favicon, against Google's OWN stated requirements:
        // "Google Search supports the following favicon file formats: BMP,
        // GIF, ICO, PNG, JPEG, PPM, and TIFF" and "must be a square (1:1
        // aspect ratio) that's at least 8x8px".
        //
        // Every failure here is silent by nature — the icon renders perfectly
        // in the browser tab, so the only symptom is a blank square in the
        // search result, months later, with nothing to attribute it to.
        $url = (string) ($this->favicon['url'] ?? '');
        if ($url === '') {
            // The shells fall back to an inline SVG data URI. That keeps the
            // browser tab happy and does nothing for Search: SVG is not on
            // Google's list, and a data: URI is neither crawlable nor a
            // stable URL, both of which Google requires.
            $this->warn('(site)', 'No favicon is set, so the shell falls back to an inline SVG — which Google cannot use (SVG is not a supported favicon format, and a data: URI is not crawlable). Set one on Site Settings to get an icon in search results.');

            return;
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        if (!\in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'bmp', 'ppm', 'tif', 'tiff'], true)) {
            $this->warn('(site)', sprintf('The favicon is .%s, which Google Search does not support (BMP, GIF, ICO, PNG, JPEG, PPM, TIFF only) — it will not appear in search results.', $ext));
        }

        $w = (int) ($this->favicon['width'] ?? 0);
        $h = (int) ($this->favicon['height'] ?? 0);
        if ($w > 0 && $h > 0 && $w !== $h) {
            $this->warn('(site)', sprintf('The favicon is %dx%d — Google requires a square (1:1) image and will not show a non-square one. Upload a square source image.', $w, $h));
        }
        if ($w > 0 && $w < 8) {
            $this->warn('(site)', sprintf('The favicon is %dpx wide — Google requires at least 8x8px (48x48 or larger is recommended).', $w));
        }
    }

    public function auditPage(string $path, string $html): void
    {
        if (!str_ends_with($path, '.html')) {
            return;
        }

        // `<div data-fs-embed>…</div>` regions are trusted files-library
        // widgets (calculators etc.) that intentionally ship their own
        // JS/CSS/markup. They are exempt from the zero-JS / external-resource /
        // image-dimension / crawlable-link checks; the rest of the page is
        // still audited normally.
        $auditable = str_contains($html, 'data-fs-embed') ? $this->stripEmbeds($html) : $html;
        foreach ($this->userScripts as $block) {
            if ($block !== '') {
                $auditable = str_replace($block, '', $auditable);
            }
        }

        $this->checkNoExternalResources($path, $auditable);
        $this->checkNoScripts($path, $auditable);
        $this->checkImages($path, $auditable);
        $this->checkHeadings($path, $html);
        $this->checkHead($path, $html);
        $this->checkCanonicalApex($path, $html);
        $this->checkCrawlableLinks($path, $auditable);
        $this->checkByteWeight($path, $html);
        $this->checkCtaTracking($path, $html);
    }

    /**
     * GTM CTA attributes: with the site toggle on, the CtaAnnotator pass ran
     * over this page, so every link/button must carry data-cta/-text/
     * -language/-location. Runs on the page MINUS the injected user-script
     * blocks (those are injected after annotation, exactly like the asset
     * checks treat them) but INCLUDING data-fs-embed regions — the annotator
     * covers those too. CtaAnnotator::missing applies the same skip rules
     * (script/svg/template/… content) the annotator itself used.
     */
    private function checkCtaTracking(string $path, string $html): void
    {
        if (!$this->ctaTracking) {
            return;
        }
        foreach ($this->userScripts as $block) {
            if ($block !== '') {
                $html = str_replace($block, '', $html);
            }
        }
        foreach (CtaAnnotator::missing($html) as $snippet) {
            $this->fail($path, 'A link/button is missing the CTA tracking attributes (data-cta*): ' . $snippet);
        }
    }

    /**
     * Removes every `<div data-fs-embed …>…</div>` span with balanced-div
     * depth counting (embed widgets contain nested divs, so a non-greedy regex
     * would mis-cut). Used only to exempt trusted embeds from asset checks.
     */
    private function stripEmbeds(string $html): string
    {
        $marker = '<div data-fs-embed';
        while (($start = stripos($html, $marker)) !== false) {
            $tagEnd = strpos($html, '>', $start);
            if ($tagEnd === false) {
                break;
            }
            $depth = 1;
            $i = $tagEnd + 1;
            $len = \strlen($html);
            while ($i < $len && $depth > 0) {
                $nextOpen = stripos($html, '<div', $i);
                $nextClose = stripos($html, '</div>', $i);
                if ($nextClose === false) {
                    $i = $len;
                    break;
                }
                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    ++$depth;
                    $i = $nextOpen + 4;
                } else {
                    --$depth;
                    $i = $nextClose + 6;
                }
            }
            $html = substr($html, 0, $start) . ' ' . substr($html, $i);
        }

        return $html;
    }

    /**
     * Called by the build for a content page whose body renders images while
     * none was prioritised for LCP — no featured_media_id, no hero-sized
     * first image (or a featured image that no longer resolves). Such a page
     * almost certainly ships its LCP as loading="lazy" with no preload, which
     * Lighthouse fails; it costs a performance point while every other engine
     * signal reports the page clean, so the build is where it has to say so.
     * A WARN, never a FAIL: a page whose real LCP is text is legitimate.
     */
    public function warnUnprioritizedLcp(string $path): void
    {
        $this->warn($path, 'Page has images but no prioritised LCP image — its hero likely ships loading="lazy" with no preload. Set featured_media_id (a hero-sized first body image is prioritised automatically).');
    }

    public function auditCss(string $css): void
    {
        $bytes = \strlen($css);
        if ($bytes > self::CSS_FAIL_BYTES) {
            $this->fail('(css)', sprintf('Inlined CSS is %d KB — over the %d KB hard limit.', $bytes / 1024, self::CSS_FAIL_BYTES / 1024));
        } elseif ($bytes > self::CSS_WARN_BYTES) {
            $this->warn('(css)', sprintf('Inlined CSS is %d KB — over the %d KB soft budget.', $bytes / 1024, self::CSS_WARN_BYTES / 1024));
        }
    }

    /**
     * @return list<array{severity: string, page: string, message: string}>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function hasFailures(): bool
    {
        foreach ($this->findings as $f) {
            if ($f['severity'] === 'FAIL') {
                return true;
            }
        }

        return false;
    }

    private function checkNoExternalResources(string $path, string $html): void
    {
        // Only FETCHED resources count against the zero-third-party rule:
        // <img/script/source/video/iframe src>, <link rel=stylesheet href>.
        // Navigation <a href> and metadata <link rel=canonical|alternate>,
        // <meta>, and preload hints are NOT fetched render-blocking assets.
        // When user scripts are allowed, external <script src> is exempt (a
        // site's own analytics/widget) but every other external fetch still fails.
        $tags = $this->allowScripts ? 'img|source|video|iframe|audio|track|embed' : 'img|script|source|video|iframe|audio|track|embed';
        if (preg_match_all('/<(?:' . $tags . ')\b[^>]*\bsrc="(https?:\/\/[^"]+)"/i', $html, $m) > 0) {
            foreach ($m[1] as $url) {
                $this->fail($path, 'External resource fetched from ' . parse_url($url, PHP_URL_HOST));
            }
        }
        if (preg_match_all('/<link\b[^>]*\brel="stylesheet"[^>]*\bhref="(https?:\/\/[^"]+)"/i', $html, $m2) > 0) {
            foreach ($m2[1] as $url) {
                $this->fail($path, 'External stylesheet from ' . parse_url($url, PHP_URL_HOST));
            }
        }
    }

    private function checkNoScripts(string $path, string $html): void
    {
        if ($this->allowScripts) {
            return; // the site opted into user scripts (#13).
        }
        if (preg_match('/<script\b(?![^>]*type="application\/ld\+json")/i', $html) === 1) {
            $this->fail($path, 'Contains a <script> element — the static output must ship zero JavaScript.');
        }
    }

    private function checkImages(string $path, string $html): void
    {
        if (preg_match_all('/<img\b[^>]*>/i', $html, $imgs) === false) {
            return;
        }
        foreach ($imgs[0] as $img) {
            if (preg_match('/\bwidth="\d+"/i', $img) !== 1 || preg_match('/\bheight="\d+"/i', $img) !== 1) {
                $this->fail($path, 'An <img> is missing width/height (causes layout shift): ' . mb_substr($img, 0, 80));
            }
            if (preg_match('/\balt="/i', $img) !== 1) {
                $this->fail($path, 'An <img> is missing alt text: ' . mb_substr($img, 0, 80));
            }
        }
    }

    private function checkHeadings(string $path, string $html): void
    {
        $h1 = preg_match_all('/<h1\b/i', $html);
        if ($h1 > 1) {
            $this->warn($path, sprintf('%d <h1> elements — a page should have exactly one.', $h1));
        }
    }

    private function checkHead(string $path, string $html): void
    {
        if (!str_contains($html, '</head>')) {
            return; // fragments (sitemap etc.) already skipped by extension
        }
        if (preg_match('/<title>[^<]/i', $html) !== 1) {
            $this->fail($path, 'Missing or empty <title>.');
        }
        if (preg_match('/<meta[^>]+name="description"/i', $html) !== 1) {
            $this->warn($path, 'Missing meta description.');
        }
    }

    /**
     * Duplicate-content guard: every canonical must name the site's ONE
     * canonical host — the apex by default, or the www. form when the operator
     * chose it — so it agrees with the host-level 301. Extract each <link> tag
     * first so href/rel order doesn't matter (an agent shell controls
     * attribute order).
     */
    private function checkCanonicalApex(string $path, string $html): void
    {
        if ($this->canonicalHost === '' || preg_match_all('/<link\b[^>]*>/i', $html, $links) === false) {
            return;
        }
        $expected = strtolower($this->canonicalHost);
        foreach ($links[0] as $link) {
            if (preg_match('/\brel="canonical"/i', $link) !== 1) {
                continue;
            }
            if (preg_match('/\bhref="(https?:\/\/[^"]+)"/i', $link, $m) !== 1) {
                continue;
            }
            $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
            if ($host !== '' && $host !== $expected) {
                $this->warn($path, 'Canonical points at ' . $host . ' — the site\'s canonical host is ' . $expected . ' (duplicate content).');
            }
        }
    }

    /**
     * Crawlable links (Google): a nav link must have a real href. An in-page
     * fragment (#anchor, e.g. the skip-link) is fine; a bare "#", empty href,
     * href-less <a>, or javascript: is not crawlable.
     */
    private function checkCrawlableLinks(string $path, string $html): void
    {
        if (preg_match_all('/<a\b[^>]*>/i', $html, $anchors) === false) {
            return;
        }
        foreach ($anchors[0] as $a) {
            if (preg_match('/\bhref="([^"]*)"/i', $a, $h) !== 1) {
                $this->warn($path, 'A link has no href (not crawlable): ' . mb_substr($a, 0, 80));
                continue;
            }
            $href = trim($h[1]);
            if ($href === '' || $href === '#' || preg_match('/^javascript:/i', $href) === 1) {
                $this->warn($path, 'A link is not crawlable (href="' . mb_substr($href, 0, 40) . '"): ' . mb_substr($a, 0, 80));
            }
        }
    }

    private function checkByteWeight(string $path, string $html): void
    {
        $bytes = \strlen($html);
        if ($bytes > self::PAGE_WARN_BYTES) {
            $this->warn($path, sprintf('Page is %d KB — large payload; trim the body or move media out of the HTML.', $bytes / 1024));
        }
    }

    private function fail(string $page, string $message): void
    {
        $this->findings[] = ['severity' => 'FAIL', 'page' => $page, 'message' => $message];
    }

    private function warn(string $page, string $message): void
    {
        $this->findings[] = ['severity' => 'WARN', 'page' => $page, 'message' => $message];
    }
}
