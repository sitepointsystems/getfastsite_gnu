<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Build\Dto\BuildStats;
use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Fs\Dirs;
use Fastsite\Site\Dto\Site;
use MatthiasMullie\Minify\CSS as CssMinifier;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Full static build of one site into sites/{handle}/build/.
 *
 * M1 scope: primary locale only, no media pipeline, no components. The
 * PageSpeed-100 renderer rules that already apply here: single inlined
 * minified CSS, zero JS, full head (title/description/canonical/OG/JSON-LD),
 * sitemap + robots + _headers + _redirects, minified HTML.
 */
final class BuildService
{
    /** Warn when a deployment approaches Cloudflare Pages' ~20k file cap. */
    private const DIST_FILE_WARN = 15000;

    /** A published page with this slug becomes the site root (`/`). */
    private const HOME_SLUG = 'home';

    /** locale → flag country-code (for /flags/{cc}.svg). Unmapped = no flag. */
    private const FLAG_COUNTRY = [
        'en' => 'gb', 'da' => 'dk', 'de' => 'de', 'sv' => 'se', 'no' => 'no', 'nb' => 'no', 'nn' => 'no',
        'fi' => 'fi', 'is' => 'is', 'fr' => 'fr', 'es' => 'es', 'it' => 'it', 'nl' => 'nl', 'pt' => 'pt',
        'pl' => 'pl', 'cs' => 'cz', 'sk' => 'sk', 'hu' => 'hu', 'ro' => 'ro', 'bg' => 'bg', 'el' => 'gr',
        'tr' => 'tr', 'ru' => 'ru', 'uk' => 'ua', 'ja' => 'jp', 'zh' => 'cn', 'ko' => 'kr', 'he' => 'il',
        'hi' => 'in', 'th' => 'th', 'vi' => 'vn', 'id' => 'id', 'ms' => 'my', 'et' => 'ee', 'lv' => 'lv',
        'lt' => 'lt', 'sl' => 'si', 'hr' => 'hr', 'sr' => 'rs', 'ga' => 'ie',
    ];

    /**
     * Flag URL per locale ('/flags/{cc}.svg') for the sites where the file
     * exists, plus the list of country codes to copy into dist/flags/.
     *
     * @param list<string> $locales all locales incl. primary
     * @return array{urls: array<string, string>, copy: list<string>}
     */
    private function localeFlags(array $locales): array
    {
        $flagsDir = \dirname(__DIR__, 3) . '/public/flags';
        $urls = [];
        $copy = [];
        foreach ($locales as $locale) {
            $cc = self::FLAG_COUNTRY[strtolower($locale)] ?? strtolower($locale);
            if (is_file($flagsDir . '/' . $cc . '.svg')) {
                $urls[$locale] = '/flags/' . $cc . '.svg';
                $copy[$cc] = true;
            }
        }

        return ['urls' => $urls, 'copy' => array_keys($copy)];
    }

    /**
     * Copy the used flag SVGs into dist/flags/. Cheap, only for multilingual
     * sites (the language switcher references them).
     *
     * @param list<string> $countryCodes
     */
    private function copyFlags(string $outDir, array $countryCodes): void
    {
        if ($countryCodes === []) {
            return;
        }
        $flagsDir = \dirname(__DIR__, 3) . '/public/flags';
        $dest = $outDir . '/flags';
        if (!is_dir($dest) && !mkdir($dest, 0770, true) && !is_dir($dest)) {
            return;
        }
        foreach ($countryCodes as $cc) {
            $src = $flagsDir . '/' . $cc . '.svg';
            if (is_file($src)) {
                copy($src, $dest . '/' . $cc . '.svg');
            }
        }
    }

    public function __construct(
        private readonly ContentRepository $content,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly MediaRenderer $mediaRenderer,
        private readonly \Fastsite\Translation\Repository\TranslationRepository $translations,
        private readonly ComponentRenderer $componentRenderer,
        private readonly \Fastsite\Build\Figlet\FigletRenderer $figlet,
        private readonly FileRenderer $fileRenderer,
        private readonly \Fastsite\Media\Repository\MediaRepository $media,
        private readonly \Fastsite\Site\Service\SiteScriptService $siteScripts,
    ) {
    }

    /**
     * Inject the first-visit browser-language redirect into the primary home.
     * Maps navigator.language → a non-primary locale prefix, sets a cookie so
     * it fires once, and leaves the primary locale as the default (no match).
     * Wrapped in data-fs-embed so PageAudit exempts it.
     */
    private function injectLangRedirect(string $html, Site $site): string
    {
        $map = [];
        foreach ($site->locales as $locale) {
            $prefix = rtrim($this->localePrefix($site, $locale), '/') . '/';
            $map[strtolower(substr($locale, 0, 2))] = $prefix;
        }
        if ($map === []) {
            return $html;
        }
        $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $snippet = '<div data-fs-embed style="display:none"><script>(function(){try{'
            . 'if(document.cookie.indexOf("fs_lang=")>-1)return;'
            . 'var m=' . $json . ',l=(navigator.language||"").slice(0,2).toLowerCase();'
            . 'if(m[l]){document.cookie="fs_lang="+l+";path=/;max-age=31536000";location.replace(m[l]);}'
            . '}catch(e){}})();</script></div>';

        return (string) preg_replace_callback('#<body\b[^>]*>#i', static fn (array $mch): string => $mch[0] . $snippet, $html, 1);
    }

    /**
     * Remember the visitor's chosen language across navigation: clicking a
     * language in the switcher stores an `fs_lang` cookie, and on every page
     * load the visitor is sent to that locale's variant of the current page
     * (when it exists). Zero-dependency inline JS wrapped in data-fs-embed so
     * PageAudit exempts it; injected at end of body so the switcher DOM exists.
     */
    private function injectLangPersist(string $html, string $path, Site $site): string
    {
        $current = $site->primaryLocale;
        foreach ($site->locales as $loc) {
            if (str_starts_with($path, $loc . '/')) {
                $current = $loc;
                break;
            }
        }
        $cur = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $snippet = '<div data-fs-embed style="display:none"><script>(function(){try{'
            . 'var C=' . $cur . ',nav=document.querySelector("[data-fs-langnav]");if(!nav)return;'
            . 'function gc(n){var m=document.cookie.match("(?:^|; )"+n+"=([^;]*)");return m?decodeURIComponent(m[1]):"";}'
            . 'nav.querySelectorAll("a[hreflang]").forEach(function(a){a.addEventListener("click",function(){'
            . 'document.cookie="fs_lang="+a.getAttribute("hreflang")+";path=/;max-age=31536000;samesite=lax";});});'
            . 'var p=gc("fs_lang");if(p&&p!==C){var t=nav.querySelector(\'a[hreflang="\'+p+\'"]\');'
            . 'if(t&&t.getAttribute("href")){location.replace(t.getAttribute("href"));}}'
            . '}catch(e){}})();</script></div>';

        return str_ireplace('</body>', $snippet . '</body>', $html);
    }

    /**
     * Insert a language switcher into a multilingual page — ONLY where the
     * shell explicitly asks for it: a `<!--fs:languages-->` comment or an
     * empty element carrying `data-fs-languages` (e.g. `<li data-fs-languages>
     * </li>` in a nav) is replaced in place with the engine's dropdown
     * (LangSwitcher::render). A shell that renders its own `data-fs-langnav`
     * switcher is left alone. With NEITHER, nothing is injected — placement is
     * the agent's decision, and the MCP `get_language_switcher` tool hands out
     * the markers, the rendered markup, and a custom Twig variant to place.
     * (The old behavior of auto-appending into the first <nav> is gone: a
     * switcher must never appear anywhere the designer didn't put it.)
     * `$alternates` is the page's precomputed hreflang cluster — the SAME data
     * the <head> alternates emit — so the switcher links the real locale
     * variants, translated slugs included (a same-path guess across the locale
     * trees would miss any page whose slug was translated). Missing
     * translation = absent from the cluster → not linked. The switcher is
     * text-only + same-origin links, so the page stays audit-clean.
     *
     * @param list<array{hreflang: string, href: string}> $alternates
     * @param array<string, string> $flagUrls locale → '/flags/{cc}.svg' for locales whose flag was copied to dist
     */
    private function injectLangSwitcher(string $html, string $path, array $alternates, Site $site, array $flagUrls = []): string
    {
        // Which locale is this page under.
        $current = $site->primaryLocale;
        foreach ($site->locales as $loc) {
            if (str_starts_with($path, $loc . '/')) {
                $current = $loc;
                break;
            }
        }

        // Cluster hrefs are absolute (SEO contract); the switcher links
        // root-relative paths so preview relativization applies.
        $links = [];
        foreach ($alternates as $alt) {
            $loc = (string) ($alt['hreflang'] ?? '');
            if ($loc === '' || $loc === 'x-default') {
                continue;
            }
            $links[$loc] = (string) (parse_url((string) ($alt['href'] ?? ''), PHP_URL_PATH) ?: '/');
        }
        if (\count($links) < 2) {
            return $html;
        }

        // Where the agent asked for the switcher: a comment or an empty element
        // bearing data-fs-languages. Either one is an explicit placement request.
        // The attr pattern captures the wrapper (open tag \1, close tag \3) so the
        // switcher is injected INSIDE it — <li data-fs-languages></li> stays a
        // valid <li> in a <ul> menu.
        $attrMarker = '/(<([a-z0-9]+)\b[^>]*\bdata-fs-languages\b[^>]*>)\s*(<\/\2>)/i';
        $hasComment = stripos($html, '<!--fs:languages-->') !== false;
        $hasAttr = preg_match($attrMarker, $html) === 1;

        // Explicit marker → replace it in place (agent-chosen position).
        if ($hasComment || $hasAttr) {
            $nav = LangSwitcher::render($links, $current, $flagUrls);
            if ($hasComment) {
                return str_ireplace('<!--fs:languages-->', $nav, $html);
            }

            return (string) preg_replace_callback($attrMarker, static fn (array $mm): string => $mm[1] . $nav . $mm[3], $html, 1);
        }

        // No marker: nothing is injected. Either the shell renders its own
        // switcher (data-fs-langnav — both bundled shells do, from
        // page.hreflang), or the page simply has none until the agent places
        // one. Log the omission so a shell missing it on purpose is a choice
        // and one missing it by accident is discoverable.
        if (stripos($html, 'data-fs-langnav') === false) {
            $this->logger->info(
                'Multilingual page has no language switcher; none injected. Place <!--fs:languages--> (or render your own) — the MCP get_language_switcher tool provides the markup.',
                ['site' => $site->handle, 'path' => $path],
            );
        }

        return $html;
    }

    /**
     * Convert YouTube links (an `<a href>`, or a bare URL standing alone in a
     * `<p>` or `<div>`) into a responsive, lazy-loaded iframe embed wrapped in
     * `data-fs-embed` (so the PageAudit external/zero-JS checks exempt it).
     * A bare-URL `<div>` keeps the author's container — it is usually their
     * styled video frame — and only the URL text becomes the player; a `<p>`
     * is replaced whole, since a block embed may not nest inside it. Non-video
     * YouTube URLs (channels, etc.) are left untouched.
     */
    private function expandYouTube(string $html): string
    {
        $bareUrl = '((?:https?:)?//[^<\s]*(?:youtube\.com|youtu\.be)[^<\s]*)';

        // <a href="…youtube…">label</a>
        $html = preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]*(?:youtube\.com|youtu\.be)[^"]*)"[^>]*>.*?</a>#is',
            fn (array $m): string => ($id = $this->youtubeId($m[1])) !== null ? $this->youtubeEmbed($id) : $m[0],
            $html,
        ) ?? $html;

        // A bare URL on its own line: <p>https://youtu.be/…</p>
        $html = preg_replace_callback(
            '#<p\b[^>]*>\s*' . $bareUrl . '\s*</p>#is',
            fn (array $m): string => ($id = $this->youtubeId($m[1])) !== null ? $this->youtubeEmbed($id) : $m[0],
            $html,
        ) ?? $html;

        // A bare URL as a <div>'s only content: <div class="video-frame">https://…</div>
        $html = preg_replace_callback(
            '#(<div\b[^>]*>)\s*' . $bareUrl . '\s*(</div>)#is',
            fn (array $m): string => ($id = $this->youtubeId($m[2])) !== null ? $m[1] . $this->youtubeEmbed($id) . $m[3] : $m[0],
            $html,
        ) ?? $html;

        return $html;
    }

    private function youtubeId(string $url): ?string
    {
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:[^"&]*&)*v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function youtubeEmbed(string $id): string
    {
        $id = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $id);

        return '<div data-fs-embed class="fs-video" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1.5rem 0">'
            . '<iframe src="https://www.youtube.com/embed/' . $id . '" title="YouTube video player" loading="lazy" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
            . 'allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"></iframe></div>';
    }

    /**
     * Inject a site's user scripts into a rendered page. Replacement strings are
     * injected literally (str_ireplace / callback) so `$`/`\` in script bodies
     * are never treated as regex backreferences.
     *
     * @param array{head: string, body_open: string, body_end: string} $scripts
     */
    private function injectScripts(string $html, array $scripts): string
    {
        if ($scripts['head'] !== '') {
            $html = str_ireplace('</head>', $scripts['head'] . '</head>', $html);
        }
        if ($scripts['body_open'] !== '') {
            $html = (string) preg_replace_callback('#<body\b[^>]*>#i', static fn (array $m): string => $m[0] . "\n" . $scripts['body_open'], $html, 1);
        }
        if ($scripts['body_end'] !== '') {
            $html = str_ireplace('</body>', $scripts['body_end'] . '</body>', $html);
        }

        return $html;
    }

    /**
     * Resolve a site's favicon/logo media to public /media/{id}/… URLs and
     * mark their ids used (so the build copies them into dist). Favicon takes
     * the smallest responsive variant; logos take the largest.
     *
     * @param array<int, true> $usedMedia
     * @return array<string, string>
     */
    private function resolveBranding(Site $site, array &$usedMedia): array
    {
        $ids = array_values(array_filter([
            $site->faviconMediaId,
            $site->logoHeaderMediaId,
            $site->logoFooterMediaId,
            $site->socialImageMediaId,
        ]));
        if ($ids === []) {
            return [];
        }
        $loaded = $this->media->loadManyWithVariants($site->handle, $ids);

        // $target null → smallest responsive variant (favicon). Otherwise the
        // smallest variant at least $target wide (so a logo isn't served at
        // full resolution), falling back to the largest available.
        $url = static function (?int $id, ?int $target) use ($loaded, &$usedMedia): ?string {
            if ($id === null || !isset($loaded[$id])) {
                return null;
            }
            $responsive = array_values(array_filter(
                $loaded[$id]->variants,
                static fn ($v): bool => $v->purpose === 'responsive',
            ));
            if ($responsive === []) {
                return null;
            }
            usort($responsive, static fn ($a, $b): int => $a->width <=> $b->width);
            $chosen = $responsive[0];
            if ($target !== null) {
                $chosen = $responsive[\count($responsive) - 1]; // largest as fallback
                foreach ($responsive as $v) {
                    if ($v->width >= $target) {
                        $chosen = $v;
                        break;
                    }
                }
            }
            $usedMedia[$id] = true;

            return '/media/' . $id . '/' . basename($chosen->diskPath);
        };

        $out = [];
        if (($u = $url($site->faviconMediaId, null)) !== null) {
            $out['favicon_url'] = $u;
        }
        if (($u = $url($site->logoHeaderMediaId, $site->logoMaxWidth)) !== null) {
            $out['logo_header_url'] = $u;
        }
        if (($u = $url($site->logoFooterMediaId, $site->logoMaxWidth)) !== null) {
            $out['logo_footer_url'] = $u;
        }

        // Default share card. Prefers the 1200x630 'og' crop the media pipeline
        // already renders; falls back to the largest responsive variant so a
        // pre-cropped upload still works. Social scrapers do not resolve
        // root-relative paths, so this one is absolute — unlike the logos.
        $id = $site->socialImageMediaId;
        if ($id !== null && isset($loaded[$id])) {
            $chosen = null;
            foreach ($loaded[$id]->variants as $variant) {
                if ($variant->purpose === 'og') {
                    $chosen = $variant;
                    break;
                }
                if ($variant->purpose === 'responsive' && ($chosen === null || $variant->width > $chosen->width)) {
                    $chosen = $variant;
                }
            }
            if ($chosen !== null) {
                $usedMedia[$id] = true;
                $out['social_image_url'] = $this->url($site, '/media/' . $id . '/' . basename($chosen->diskPath));
                $out['social_image_width'] = $chosen->width;
                $out['social_image_height'] = $chosen->height;
                $out['social_image_alt'] = $loaded[$id]->alt;
            }
        }

        return $out;
    }

    /**
     * Dry-run render of the representative page set (home, blog index, one
     * post, one page, 404) through the CURRENT shell, returning PageAudit
     * FAIL messages. Renders in memory only — no disk writes, no publish.
     *
     * Called synchronously when a shell file is written so a template that
     * would throw (sandbox violation, Twig error) or FAIL the audit is
     * rejected at the API boundary instead of wedging the async build.
     *
     * @return list<string> FAIL messages (empty = the shell is safe to build)
     */
    public function validateShell(Site $site): array
    {
        $sitePath = $this->databases->sitePath($site->handle);
        $shellPath = $sitePath . '/shell';
        if (!is_dir($shellPath . '/templates')) {
            return ['Shell has no templates directory.'];
        }

        try {
            $twig = $this->twig($site, $shellPath, $site->primaryLocale);
            $inlineCss = $this->compileCss($shellPath);
            $twig->addGlobal('inline_css', $inlineCss);

            $audit = new PageAudit();
            $audit->auditCss($inlineCss);

            $posts = \array_slice($this->content->listPublished($site->handle, 'post'), 0, 1);
            $pages = \array_slice($this->content->listPublished($site->handle, 'page'), 0, 1);
            $postCards = array_map(fn (Content $c): array => $this->card($site, $c), $posts);

            $samples = [];
            $samples['index.html'] = $this->renderPage($twig, 'home.html.twig', [
                'page' => ['title' => $site->name, 'seo_title' => $site->name, 'seo_description' => 'x', 'canonical' => $this->url($site, '/'), 'og_type' => 'website'],
                'content' => null,
                'posts' => $postCards,
                'json_ld' => $this->websiteJsonLd($site),
            ]);
            $samples['blog/index.html'] = $this->renderPage($twig, 'blog-index.html.twig', [
                'page' => ['title' => 'Blog', 'seo_title' => 'Blog', 'seo_description' => 'x', 'canonical' => $this->url($site, '/blog/'), 'og_type' => 'website'],
                'posts' => $postCards,
                'json_ld' => null,
            ]);
            $samples['404.html'] = $this->renderPage($twig, '404.html.twig', [
                'page' => ['title' => 'x', 'seo_title' => 'x', 'seo_description' => 'x', 'canonical' => '', 'og_type' => 'website'],
                'json_ld' => null,
            ]);
            foreach ($posts as $post) {
                [$content] = $this->renderContent($site, $post);
                $samples['post.html'] = $this->renderPage($twig, 'post.html.twig', [
                    'page' => $this->pageContext($site, $post, 'article') + ['preload' => null],
                    'content' => $content,
                    'json_ld' => $this->articleJsonLd($site, $post),
                ]);
            }
            foreach ($pages as $page) {
                [$content] = $this->renderContent($site, $page);
                $samples['page.html'] = $this->renderPage($twig, 'page.html.twig', [
                    'page' => $this->pageContext($site, $page, 'website') + ['preload' => null],
                    'content' => $content,
                    'json_ld' => null,
                ]);
            }

            foreach ($samples as $path => $html) {
                $audit->auditPage($path, $html);
            }
        } catch (\Throwable $e) {
            return ['Shell template error: ' . $e->getMessage()];
        }

        return array_values(array_map(
            static fn (array $f): string => $f['message'] . ' (' . $f['page'] . ')',
            array_filter($audit->findings(), static fn (array $f): bool => $f['severity'] === 'FAIL'),
        ));
    }

    /**
     * @param \Closure(): void|null $tick progress heartbeat for the queue watchdog
     */
    public function build(Site $site, ?\Closure $tick = null): BuildStats
    {
        $tick ??= static function (): void {};
        $start = microtime(true);

        $sitePath = $this->databases->sitePath($site->handle);
        $shellPath = $sitePath . '/shell';
        $outDir = $sitePath . '/build';

        if (!is_dir($shellPath . '/templates')) {
            throw new \RuntimeException(sprintf('Site "%s" has no shell templates at %s.', $site->handle, $shellPath));
        }

        $inlineCss = $this->compileCss($shellPath);
        $tick();

        $posts = $this->content->listPublished($site->handle, 'post');
        $pages = $this->content->listPublished($site->handle, 'page');

        // Home-page-as-content: a published page whose primary slug is `home`
        // BECOMES the site root (`/`), rendered through home.html.twig with its
        // body available as `content.body_html`. It's then excluded from the
        // normal pages loop so it never also renders at `/home/`. Without one,
        // the home template renders standalone (its own hero) as before.
        $homePageId = null;
        foreach ($pages as $p) {
            if ($p->slug === self::HOME_SLUG) {
                $homePageId = $p->id;
                break;
            }
        }

        // Which locales render each content item, and at what path — drives
        // hreflang and the per-locale trees. Primary always; a secondary
        // locale only when a usable translation exists (missing = absent).
        $locales = $this->buildLocales($site);
        $translationsByLocale = [];
        foreach ($locales as $locale) {
            $translationsByLocale[$locale] = $locale === $site->primaryLocale
                ? []
                : $this->translations->usableByLocale($site->handle, $locale);
        }
        $altLinks = $this->alternateLinks($site, $locales, $posts, $pages, $translationsByLocale);

        /** @var array<int, true> $usedMedia media ids referenced anywhere this build */
        $usedMedia = [];
        /** @var array<int, true> $usedFiles file ids referenced anywhere this build */
        $usedFiles = [];
        $outputs = [];
        $rendered = 0;
        /** @var list<array<string, mixed>> $primaryPostCards */
        $primaryPostCards = [];
        /** @var list<array<string, mixed>> $primaryPageCards */
        $primaryPageCards = [];

        // Favicon/logo URLs resolved once (their media ids are marked used so
        // copyMedia ships them), then injected into every locale's shell.
        $branding = $this->resolveBranding($site, $usedMedia);
        // Language switcher: flag URL per locale (multilingual sites only).
        $isMultilingual = $site->locales !== [];
        $flagInfo = $isMultilingual
            ? $this->localeFlags([$site->primaryLocale, ...$site->locales])
            : ['urls' => [], 'copy' => []];
        $branding['flags'] = $flagInfo['urls'];
        // Language display names per locale ("da" → "Danish"), so shells can
        // label the switcher like the dashboard does.
        $localeNames = [];
        foreach ([$site->primaryLocale, ...$site->locales] as $loc) {
            $localeNames[$loc] = \Fastsite\Shared\Locales::name($loc);
        }
        $branding['locale_names'] = $localeNames;

        // Per-output hreflang cluster (same data the <head> alternates use) so
        // the language switcher links the REAL locale variants — translated
        // slugs included, which a same-path guess across trees would miss.
        /** @var array<string, list<array{hreflang: string, href: string}>> $pageAlternates */
        $pageAlternates = [];

        foreach ($locales as $locale) {
            $twig = $this->twig($site, $shellPath, $locale, $branding);
            $twig->addGlobal('inline_css', $inlineCss);
            $prefix = $this->localePrefix($site, $locale);

            $localePosts = $this->localizeList($posts, $locale, $translationsByLocale[$locale], $site);
            $localePages = $this->localizeList($pages, $locale, $translationsByLocale[$locale], $site);

            // Pull the home page out of the pages list so it renders ONLY at the
            // root (not also at /home/) and never appears in the page card lists.
            $homeEntry = null;
            if ($homePageId !== null) {
                foreach ($localePages as $k => $e) {
                    if ($e['id'] === $homePageId) {
                        $homeEntry = $e;
                        unset($localePages[$k]);
                        break;
                    }
                }
                $localePages = array_values($localePages);
            }

            $postCards = array_map(fn (array $c): array => $c['card'], $localePosts);
            if ($locale === $site->primaryLocale) {
                $primaryPostCards = $postCards;
                $primaryPageCards = array_map(fn (array $c): array => $c['card'], $localePages);
            }

            // Engine-owned paths are written FIRST and reserved, so a stray
            // content page can never silently clobber a locale home/index.
            $homePath = trim($prefix, '/') === '' ? 'index.html' : trim($prefix, '/') . '/index.html';
            $this->reserveOutput($outputs, $homePath, $site);
            if ($homeEntry !== null) {
                // The home page IS content — feed its rendered body to the home
                // template, but keep the canonical/hreflang anchored at the root.
                $usedMedia += array_fill_keys($homeEntry['media_ids'], true);
                $usedFiles += array_fill_keys($homeEntry['file_ids'], true);
                $homePageCtx = $homeEntry['page'];
                $homePageCtx['canonical'] = $this->url($site, $prefix . '/');
                $homePageCtx['og_type'] = 'website';
                $homePageCtx['hreflang'] = $altLinks['home'] ?? [];
                $outputs[$homePath] = $this->renderPage($twig, 'home.html.twig', [
                    'page' => $homePageCtx,
                    'content' => $homeEntry['content'],
                    'posts' => \array_slice($postCards, 0, 10),
                    'json_ld' => $locale === $site->primaryLocale ? $this->websiteJsonLd($site) : null,
                ]);
            } else {
                $outputs[$homePath] = $this->renderPage($twig, 'home.html.twig', [
                    'page' => $this->simplePage($site->name, $this->url($site, $prefix . '/'), $altLinks['home'] ?? [], $site->metaDescription),
                    'content' => null,
                    'posts' => \array_slice($postCards, 0, 10),
                    'json_ld' => $locale === $site->primaryLocale ? $this->websiteJsonLd($site) : null,
                ]);
            }

            $pageAlternates[$homePath] = $altLinks['home'] ?? [];

            $blogIndexPath = trim($prefix . '/blog', '/') . '/index.html';
            $this->reserveOutput($outputs, $blogIndexPath, $site);
            $outputs[$blogIndexPath] = $this->renderPage($twig, 'blog-index.html.twig', [
                'page' => $this->simplePage('Blog — ' . $site->name, $this->url($site, $prefix . '/blog/'), $altLinks['blog'] ?? [], $site->metaDescription),
                'posts' => $postCards,
                'json_ld' => null,
            ]);
            $pageAlternates[$blogIndexPath] = $altLinks['blog'] ?? [];

            foreach ($localePosts as $entry) {
                if ((++$rendered % 25) === 0) {
                    $tick();
                }
                $path = trim($prefix, '/') === ''
                    ? $this->outputPath($site->postPathPattern, $entry['slug'])
                    : trim($prefix, '/') . '/' . $this->outputPath($site->postPathPattern, $entry['slug']);
                if (isset($outputs[$path])) {
                    $this->logger->warning('Output path collision — content skipped', ['site' => $site->handle, 'path' => $path]);
                    continue;
                }
                $usedMedia += array_fill_keys($entry['media_ids'], true);
                $usedFiles += array_fill_keys($entry['file_ids'], true);
                $outputs[$path] = $this->renderPage($twig, 'post.html.twig', [
                    'page' => $entry['page'] + ['hreflang' => $altLinks['content'][$entry['id']] ?? []],
                    'content' => $entry['content'],
                    'json_ld' => $entry['json_ld'],
                ]);
                $pageAlternates[$path] = $altLinks['content'][$entry['id']] ?? [];
            }

            foreach ($localePages as $entry) {
                if ((++$rendered % 25) === 0) {
                    $tick();
                }
                $path = trim($prefix, '/') === ''
                    ? $this->outputPath($site->pagePathPattern, $entry['slug'])
                    : trim($prefix, '/') . '/' . $this->outputPath($site->pagePathPattern, $entry['slug']);
                if (isset($outputs[$path])) {
                    $this->logger->warning('Output path collision — content skipped', ['site' => $site->handle, 'path' => $path]);
                    continue;
                }
                $usedMedia += array_fill_keys($entry['media_ids'], true);
                $usedFiles += array_fill_keys($entry['file_ids'], true);
                $outputs[$path] = $this->renderPage($twig, 'page.html.twig', [
                    'page' => $entry['page'] + ['hreflang' => $altLinks['content'][$entry['id']] ?? []],
                    'content' => $entry['content'],
                    'json_ld' => $entry['json_ld'],
                ]);
                $pageAlternates[$path] = $altLinks['content'][$entry['id']] ?? [];
            }

            $outputs[trim($prefix, '/') === '' ? '404.html' : trim($prefix, '/') . '/404.html'] = $this->renderPage($twig, '404.html.twig', [
                'page' => $this->simplePage('Not found — ' . $site->name, '', [], $site->metaDescription),
                'json_ld' => null,
            ]);

            // Per-locale sitemap.
            $sitemapName = $locale === $site->primaryLocale ? 'sitemap-' . $locale . '.xml' : 'sitemap-' . $locale . '.xml';
            $outputs[$sitemapName] = $this->localeSitemap($site, $prefix, $localePosts, $localePages);
        }

        // User scripts (opt-in): inject into every page, then relax CSP + audit
        // for this site — it has knowingly traded away the zero-JS guarantee.
        $scripts = $this->siteScripts->renderByLocation($site->handle);
        $hasScripts = $scripts['head'] !== '' || $scripts['body_open'] !== '' || $scripts['body_end'] !== '';
        if ($hasScripts) {
            foreach ($outputs as $path => $html) {
                if (str_ends_with($path, '.html')) {
                    $outputs[$path] = $this->injectScripts($html, $scripts);
                }
            }
        }

        // Render the language switcher where the shell placed a marker (never
        // auto-inserted). Uses $outputs as the source of truth for which
        // locale variants of each page exist.
        if ($isMultilingual) {
            foreach ($outputs as $path => $html) {
                if (str_ends_with($path, '.html') && !str_ends_with($path, '404.html')) {
                    $html = $this->injectLangSwitcher($html, $path, $pageAlternates[$path] ?? [], $site, $flagInfo['urls']);
                    // Remember the chosen language across navigation (cookie).
                    $outputs[$path] = $this->injectLangPersist($html, $path, $site);
                }
            }
        }

        // The minifier deliberately preserves the <!--fs:languages--> marker
        // for the pass above — any marker nothing replaced (single-language
        // site, 404, or a page with fewer than 2 built variants) must not ship.
        foreach ($outputs as $path => $html) {
            if (str_ends_with($path, '.html') && stripos($html, '<!--fs:languages-->') !== false) {
                $outputs[$path] = str_ireplace('<!--fs:languages-->', '', $html);
            }
        }

        // Browser-language redirect on the primary home (#12): a tiny inline
        // script (data-fs-embed → audit-exempt) sends a first-time visitor to
        // their locale, primary as default. Only for multilingual sites.
        if ($isMultilingual && isset($outputs['index.html'])) {
            $outputs['index.html'] = $this->injectLangRedirect($outputs['index.html'], $site);
        }

        // Sitemap index pointing at each locale's sitemap.
        $outputs['sitemap.xml'] = $this->sitemapIndex($site, $locales);
        $outputs['robots.txt'] = $this->robots($site);
        $outputs['llms.txt'] = $this->llmsTxt($site, $primaryPostCards, $primaryPageCards);
        // CSP: full relax for user scripts; inline-only for the lang redirect.
        $outputs['_headers'] = $this->headers($hasScripts ? 'full' : ($isMultilingual ? 'inline' : 'none'));
        $redirects = $this->redirectsFile($site, array_keys($outputs));
        if ($redirects !== '') {
            $outputs['_redirects'] = $redirects;
        }

        $tick();
        $mediaFiles = $this->copyMedia($site, $sitePath, $outDir, array_keys($usedMedia));
        $this->copyFiles($site, $sitePath, $outDir, array_keys($usedFiles));
        $this->copyFlags($outDir, $flagInfo['copy']);

        // PageSpeed-100 audit over every emitted page + the inlined CSS. When a
        // site ships user scripts, the zero-JS FAIL is relaxed for that site.
        $audit = new PageAudit($hasScripts);
        $audit->auditCss($inlineCss);
        foreach ($outputs as $path => $html) {
            $audit->auditPage($path, $html);
        }
        $this->reportAudit($site, $audit);

        [$written, $skipped, $deleted, $bytes] = $this->writeOutputs($site, $outDir, $outputs);

        $this->databases->checkpoint($this->databases->site($site->handle));

        // Cloudflare Pages hard-caps a deployment at ~20k files. WARN well
        // before that so there's runway to flip old media to R2 (plan
        // §dist-count guard). A smell, not a pipeline failure — never blocks.
        $distFiles = $this->distFileCount($outDir);
        if ($distFiles >= self::DIST_FILE_WARN) {
            $this->logger->warning('Dist file count is high', [
                'site' => $site->handle,
                'files' => $distFiles,
                'cf_pages_cap' => 20000,
                'hint' => 'Flip the oldest media to R2 storage to stay under the Cloudflare Pages cap.',
            ]);
        }

        $stats = new BuildStats(
            pages: \count($outputs),
            written: $written,
            skipped: $skipped,
            deleted: $deleted,
            bytes: $bytes,
            durationMs: round((microtime(true) - $start) * 1000, 1),
            outputDir: $outDir,
        );

        $this->logger->info('Build finished', [
            'site' => $site->handle,
            'pages' => $stats->pages,
            'written' => $stats->written,
            'deleted' => $stats->deleted,
            'media_files' => $mediaFiles,
            'ms' => $stats->durationMs,
        ]);

        return $stats;
    }

    /** Total files in the deployed tree (pages + media + assets). */
    private function distFileCount(string $outDir): int
    {
        if (!is_dir($outDir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                ++$count;
            }
        }

        return $count;
    }

    private function reportAudit(Site $site, PageAudit $audit): void
    {
        foreach ($audit->findings() as $finding) {
            $level = $finding['severity'] === 'FAIL' ? 'error' : 'warning';
            $this->logger->{$level}('PageAudit ' . $finding['severity'], [
                'site' => $site->handle,
                'page' => $finding['page'],
                'message' => $finding['message'],
            ]);
        }

        if ($audit->hasFailures()) {
            $fails = array_filter($audit->findings(), static fn (array $f): bool => $f['severity'] === 'FAIL');
            throw new \RuntimeException(sprintf(
                'PageAudit found %d pipeline-invariant failure(s) — build blocked. First: %s (%s)',
                \count($fails),
                reset($fails)['message'],
                reset($fails)['page'],
            ));
        }
    }

    /**
     * Shell templates are authored by (potentially leaked-key) agents, so
     * this environment is SANDBOXED: a template can only use the
     * presentational tags/filters/functions on the allowlist. Server-side
     * escape gadgets (`{{ [x]|map('system') }}`, `source()`, arbitrary
     * method calls) throw instead of running as www-data on the build host.
     */
    /**
     * @param array<string, mixed> $siteExtra merged into the `site` twig global
     */
    private function twig(Site $site, string $shellPath, string $locale, array $siteExtra = []): Environment
    {
        $twig = new Environment(new FilesystemLoader($shellPath . '/templates'), [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $twig->addExtension(new SandboxExtension($this->securityPolicy(), true));

        // Per-locale UI strings: shell/i18n/{locale}.json, falling back to
        // the primary locale's strings for any key the locale hasn't set.
        $strings = $this->localeStrings($shellPath, $site->primaryLocale);
        if ($locale !== $site->primaryLocale) {
            $strings = $this->localeStrings($shellPath, $locale) + $strings;
        }

        $twig->addFunction(new TwigFunction('t', static fn (string $key): string => (string) ($strings[$key] ?? $key)));

        // Locale-aware date filter via IntlDateFormatter so a Danish page
        // formats dates in Danish.
        $twig->addFilter(new TwigFilter('localdate', function (mixed $value, string $pattern = 'd MMM y') use ($locale): string {
            $ts = $this->toTimestamp($value);
            if ($ts === null) {
                return '';
            }
            $fmt = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern);

            return (string) $fmt->format($ts);
        }));

        $twig->addGlobal('site', [
            'handle' => $site->handle,
            'name' => $site->name,
            'base_url' => $site->baseUrl,
            'locale' => $locale,
            'primary_locale' => $site->primaryLocale,
            'locales' => $site->locales,
            'meta_description' => $site->metaDescription,
        ] + $siteExtra);

        return $twig;
    }

    /**
     * @return array<string, string>
     */
    private function localeStrings(string $shellPath, string $locale): array
    {
        $file = $shellPath . '/i18n/' . $locale . '.json';
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function toTimestamp(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }

    private function securityPolicy(): SecurityPolicy
    {
        return new SecurityPolicy(
            allowedTags: ['if', 'for', 'set', 'block', 'extends', 'include', 'apply', 'with', 'use', 'embed'],
            allowedFilters: [
                'raw', 'escape', 'e', 'default', 'date', 'format', 'number_format',
                'join', 'length', 'upper', 'lower', 'title', 'capitalize', 'trim',
                'nl2br', 'striptags', 'slice', 'first', 'last', 'keys', 'reverse',
                'sort', 'merge', 'split', 'replace', 'url_encode', 'json_encode',
                'abs', 'round', 'batch', 'column', 'spaceless',
            ],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: ['t', 'range', 'max', 'min', 'cycle', 'date', 'block', 'parent'],
        );
    }

    private function compileCss(string $shellPath): string
    {
        $assetDir = $shellPath . '/assets';
        $files = glob($assetDir . '/*.css') ?: [];

        // tokens.css always first — every other sheet reads its custom properties.
        usort($files, static function (string $a, string $b): int {
            $aTokens = str_ends_with($a, 'tokens.css') ? 0 : 1;
            $bTokens = str_ends_with($b, 'tokens.css') ? 0 : 1;

            return $aTokens <=> $bTokens ?: strcmp($a, $b);
        });

        if ($files === []) {
            return '';
        }

        $minifier = new CssMinifier();
        foreach ($files as $file) {
            $minifier->add($file);
        }

        return $minifier->minify();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderPage(Environment $twig, string $template, array $context): string
    {
        return HtmlMinifier::minify($twig->render($template, $context));
    }

    /**
     * Builds the template `content` context with media expanded: body
     * `<img data-media-id>` → `<picture>`, featured image with its LCP
     * preload link.
     *
     * @return array{0: array<string, mixed>, 1: string|null, 2: list<int>}
     */
    private function renderContent(Site $site, Content $content): array
    {
        $card = $this->card($site, $content, '');

        [$context, $preload, $ids, , ] = $this->renderContentFields($site, $content->bodyHtml, $content->featuredMediaId, $card, $site->primaryLocale);

        return [$context, $preload, $ids];
    }

    /**
     * Expands a body into the template `content` context: components →
     * static markup, media `<img data-media-id>` → `<picture>`, featured
     * image + its LCP preload. Returns any component CSS to inline in head.
     *
     * @param array<string, mixed> $card
     * @return array{0: array<string, mixed>, 1: string|null, 2: list<int>, 3: string, 4: list<int>}
     */
    private function renderContentFields(Site $site, string $bodyHtml, ?int $featuredMediaId, array $card, string $locale): array
    {
        // Components first — their output may itself contain media/file refs.
        $expanded = $this->componentRenderer->expand($site->handle, $bodyHtml, $locale);
        foreach ($expanded['warnings'] as $warning) {
            $this->logger->warning('Component expansion', ['site' => $site->handle, 'message' => $warning]);
        }

        $body = $this->mediaRenderer->expandBody($site->handle, $expanded['html']);
        $ids = $body['media_ids'];

        // Files: <a data-file-id> → crawlable /files/ download links, then the
        // unified {{ filename }} embed (html/js inline & run, else link).
        $files = $this->fileRenderer->expandBody($site->handle, $body['html']);
        $embeds = $this->fileRenderer->expandEmbeds($site->handle, $files['html']);
        $fileIds = array_values(array_unique(array_merge($files['file_ids'], $embeds['file_ids'])));

        $preload = null;
        $featuredHtml = '';

        if ($featuredMediaId !== null) {
            $featured = $this->mediaRenderer->featured($site->handle, $featuredMediaId);
            $featuredHtml = $featured['html'];
            $preload = $featured['preload'];
            if ($featuredHtml !== '') {
                $ids[] = $featuredMediaId;
            }
        }

        // YouTube links → responsive iframe embeds (#26). Stored content keeps a
        // plain link (sanitizer-safe); the build swaps it for the player, wrapped
        // in data-fs-embed so PageAudit exempts the external frame.
        $bodyOut = $this->expandYouTube($embeds['html']);

        $context = $card + ['body_html' => $bodyOut, 'featured_html' => $featuredHtml];

        return [$context, $preload, array_values(array_unique($ids)), $expanded['css'], $fileIds];
    }

    /**
     * @return list<string>
     */
    private function buildLocales(Site $site): array
    {
        return array_values(array_unique([$site->primaryLocale, ...$site->locales]));
    }

    private function localePrefix(Site $site, string $locale): string
    {
        return $locale === $site->primaryLocale ? '' : '/' . $locale;
    }

    /**
     * If a content page already claimed an engine-owned path (home/blog
     * index), evict it with a warning rather than let it be silently
     * overwritten (or overwrite the index).
     *
     * @param array<string, string> $outputs
     */
    private function reserveOutput(array &$outputs, string $path, Site $site): void
    {
        if (isset($outputs[$path])) {
            $this->logger->warning('Content page collided with an engine-owned path — dropped', ['site' => $site->handle, 'path' => $path]);
            unset($outputs[$path]);
        }
    }

    /**
     * Localizes a content list for one locale. Primary uses source rows;
     * a secondary locale only includes items with a usable translation
     * (missing translation = page absent). Returns full render entries.
     *
     * @param list<Content> $items
     * @param array<int, \Fastsite\Translation\Dto\ContentTranslation> $translations
     * @return list<array<string, mixed>>
     */
    private function localizeList(array $items, string $locale, array $translations, Site $site): array
    {
        $prefix = $this->localePrefix($site, $locale);
        $isPrimary = $locale === $site->primaryLocale;
        $entries = [];

        foreach ($items as $item) {
            $translation = $translations[$item->id] ?? null;
            if (!$isPrimary && $translation === null) {
                continue; // absent for this locale
            }

            $title = $isPrimary ? $item->title : $translation->title;
            $excerpt = $isPrimary ? $item->excerpt : $translation->excerpt;
            $body = $isPrimary ? $item->bodyHtml : $translation->bodyHtml;
            $seoTitle = $isPrimary ? $item->seoTitle : $translation->seoTitle;
            $seoDescription = $isPrimary ? $item->seoDescription : $translation->seoDescription;
            $slug = $isPrimary ? $item->slug : $translation->slug;

            $pattern = $item->type === 'post' ? $site->postPathPattern : $site->pagePathPattern;
            $path = $prefix . str_replace('{slug}', $slug, $pattern);

            $card = [
                'title' => $title,
                'excerpt' => $excerpt,
                'slug' => $slug,
                'url' => $path,
                'published_at' => $item->publishedAt,
                'author_name' => $item->authorName,
                'featured_thumb' => $item->featuredMediaId !== null
                    ? $this->mediaRenderer->thumbnail($site->handle, $item->featuredMediaId)
                    : '',
            ];

            [$content, $preload, $ids, $componentCss, $fileIds] = $this->renderContentFields($site, $body, $item->featuredMediaId, $card, $locale);

            $page = [
                'title' => $title . ' — ' . $site->name,
                'seo_title' => $seoTitle !== '' ? $seoTitle : $title . ' — ' . $site->name,
                'seo_description' => $seoDescription !== '' ? $seoDescription : $excerpt,
                'canonical' => $this->url($site, $path),
                'og_type' => $item->type === 'post' ? 'article' : 'website',
                'preload' => $preload,
                'component_css' => $componentCss,
            ];

            $jsonLd = null;
            if ($item->type === 'post') {
                $jsonLd = $isPrimary && $item->jsonLd !== null
                    ? $this->safeStoredJsonLd($item->jsonLd)
                    : $this->articleJsonLdFor($site, $title, $this->url($site, $path), $item->publishedAt, $item->updatedAt, $item->authorName, $excerpt);
            } elseif ($isPrimary) {
                $jsonLd = $this->safeStoredJsonLd($item->jsonLd);
            }

            $entries[] = [
                'id' => $item->id,
                'slug' => $slug,
                'card' => $card,
                'page' => $page,
                'content' => $content,
                'json_ld' => $jsonLd,
                'media_ids' => $ids,
                'file_ids' => $fileIds,
            ];
        }

        return $entries;
    }

    /**
     * Precomputes hreflang clusters: for each content item, and for the
     * home/blog index, the list of {hreflang, href} alternates over the
     * locales where it actually renders, plus x-default → primary.
     *
     * @param list<string> $locales
     * @param list<Content> $posts
     * @param list<Content> $pages
     * @param array<string, array<int, \Fastsite\Translation\Dto\ContentTranslation>> $translationsByLocale
     * @return array{content: array<int, list<array{hreflang: string, href: string}>>, home: list<array{hreflang: string, href: string}>, blog: list<array{hreflang: string, href: string}>}
     */
    private function alternateLinks(Site $site, array $locales, array $posts, array $pages, array $translationsByLocale): array
    {
        if (\count($locales) < 2) {
            return ['content' => [], 'home' => [], 'blog' => []];
        }

        $byId = [];
        foreach ([...$posts, ...$pages] as $item) {
            $byId[$item->id] = $item;
        }

        $content = [];
        foreach ($byId as $id => $item) {
            $links = [];
            foreach ($locales as $locale) {
                $prefix = $this->localePrefix($site, $locale);
                if ($locale === $site->primaryLocale) {
                    $slug = $item->slug;
                } else {
                    $t = $translationsByLocale[$locale][$id] ?? null;
                    if ($t === null) {
                        continue;
                    }
                    $slug = $t->slug;
                }
                $pattern = $item->type === 'post' ? $site->postPathPattern : $site->pagePathPattern;
                $href = $this->url($site, $prefix . str_replace('{slug}', $slug, $pattern));
                $links[] = ['hreflang' => $locale, 'href' => $href];
            }
            if (\count($links) > 1) {
                $links[] = ['hreflang' => 'x-default', 'href' => $this->url($site, str_replace('{slug}', $item->slug, $item->type === 'post' ? $site->postPathPattern : $site->pagePathPattern))];
                $content[$id] = $links;
            }
        }

        // Home + blog index exist in every locale.
        $home = [];
        $blog = [];
        foreach ($locales as $locale) {
            $prefix = $this->localePrefix($site, $locale);
            $home[] = ['hreflang' => $locale, 'href' => $this->url($site, $prefix . '/')];
            $blog[] = ['hreflang' => $locale, 'href' => $this->url($site, $prefix . '/blog/')];
        }
        $home[] = ['hreflang' => 'x-default', 'href' => $this->url($site, '/')];
        $blog[] = ['hreflang' => 'x-default', 'href' => $this->url($site, '/blog/')];

        return ['content' => $content, 'home' => $home, 'blog' => $blog];
    }

    /**
     * @param list<array{hreflang: string, href: string}> $hreflang
     * @return array<string, mixed>
     */
    private function simplePage(string $title, string $canonical, array $hreflang, string $description = ''): array
    {
        return [
            'title' => $title,
            'seo_title' => $title,
            'seo_description' => $description,
            'canonical' => $canonical,
            'og_type' => 'website',
            'preload' => null,
            'hreflang' => $hreflang,
        ];
    }

    /**
     * Copies every referenced media variant into build/media/{id}/. Returns
     * the number of files placed.
     *
     * @param list<int> $mediaIds
     */
    private function copyMedia(Site $site, string $sitePath, string $outDir, array $mediaIds): int
    {
        $keep = array_fill_keys(array_map('strval', $mediaIds), true);

        $copied = 0;
        foreach ($mediaIds as $id) {
            $sourceDir = $sitePath . '/media/variants/' . $id;
            if (!is_dir($sourceDir)) {
                continue;
            }
            $targetDir = $outDir . '/media/' . $id;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create media output dir: ' . $targetDir);
            }
            foreach (glob($sourceDir . '/*') ?: [] as $file) {
                if (str_ends_with($file, '-og.jpg') && $id !== $site->socialImageMediaId) {
                    // OG crops are dist bloat — except the site's share card,
                    // which og:image/twitter:image reference by absolute
                    // self-URL, so THAT one must ship or scrapers get a 404.
                    // Drop a previously shipped crop so an incremental build
                    // stays byte-identical to a fresh one.
                    $stale = $targetDir . '/' . basename($file);
                    if (is_file($stale)) {
                        @unlink($stale);
                    }
                    continue;
                }
                $target = $targetDir . '/' . basename($file);
                // Variant filenames are immutable per media id, so existence
                // (plus a size sanity check for a truncated prior copy) is a
                // correct skip — no need to hash both files every build.
                if (!is_file($target) || filesize($file) !== filesize($target)) {
                    copy($file, $target);
                }
                ++$copied;
            }
        }

        // Prune media dirs for content no longer referenced this build.
        foreach (glob($outDir . '/media/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!isset($keep[basename($dir)])) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }

        return $copied;
    }

    /**
     * Copies every referenced file-kind original into build/files/{id}/.
     * Mirrors copyMedia: referenced-only, immutable per (id, ext), unreferenced
     * dirs pruned. Served from /files/* (immutable caching via _headers).
     *
     * @param list<int> $fileIds
     */
    private function copyFiles(Site $site, string $sitePath, string $outDir, array $fileIds): void
    {
        $keep = array_fill_keys(array_map('strval', $fileIds), true);

        foreach ($this->fileRenderer->filesForCopy($site->handle, $fileIds) as $id => $meta) {
            $source = $sitePath . '/' . $meta['diskPath'];
            if (!is_file($source)) {
                continue;
            }
            $targetDir = $outDir . '/files/' . $id;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create files output dir: ' . $targetDir);
            }
            $target = $targetDir . '/' . $meta['slug'] . '.' . $meta['ext'];
            if (!is_file($target) || filesize($source) !== filesize($target)) {
                copy($source, $target);
            }
        }

        // Prune file dirs for content no longer referenced this build.
        foreach (glob($outDir . '/files/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!isset($keep[basename($dir)])) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
    }

    /**
     * Builds the site normally, then mirrors build/ → preview/ with every
     * internal path rewritten RELATIVE to each page's depth, so the tree is
     * self-contained and viewable from ANY base (a preview URL/subpath or
     * file://) with no deploy. Production output is unchanged (absolute paths
     * for canonical/OG/sitemap SEO). Returns the preview directory.
     */
    public function buildPreview(Site $site): string
    {
        $this->build($site);
        $sitePath = $this->databases->sitePath($site->handle);
        $this->mirrorRelativized($sitePath . '/build', $sitePath . '/preview', $site);
        $this->captureCard($site, $sitePath);

        return $sitePath . '/preview';
    }

    /**
     * Best-effort homepage screenshot → {sitePath}/card.png for the Sites
     * overview thumbnail. Never fails a preview: a missing browser (e.g. the
     * dev image without chromium) just skips the thumbnail.
     */
    private function captureCard(Site $site, string $sitePath): void
    {
        $index = $sitePath . '/preview/index.html';
        if (!is_file($index)) {
            return;
        }
        $appRoot = \dirname(__DIR__, 3);
        try {
            $process = new \Symfony\Component\Process\Process(
                ['node', $appRoot . '/bin/screenshot-site.mjs', $index, $sitePath . '/card.png'],
                $appRoot,
                null,
                null,
                60,
            );
            $process->run();
            if (!$process->isSuccessful()) {
                $this->logger->info('Card thumbnail skipped', ['site' => $site->handle, 'err' => mb_substr($process->getErrorOutput(), 0, 200)]);
            }
        } catch (\Throwable $e) {
            $this->logger->info('Card thumbnail unavailable', ['site' => $site->handle, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mirror $from → $to, relativizing every .html by its depth; other files
     * (media, files, _headers, sitemaps…) are copied byte-for-byte (skipped if
     * unchanged). Stale files in $to are pruned.
     */
    private function mirrorRelativized(string $from, string $to, Site $site): void
    {
        if (!is_dir($from)) {
            return;
        }
        $kept = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($from))), '/');
            $kept[$rel] = true;
            $target = $to . '/' . $rel;
            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create preview dir: ' . $dir);
            }
            if (str_ends_with($rel, '.html')) {
                file_put_contents($target, PreviewRelativizer::relativize((string) file_get_contents($file->getPathname()), $rel, $site->baseUrl));
            } elseif (!is_file($target) || filesize($file->getPathname()) !== filesize($target)) {
                copy($file->getPathname(), $target);
            }
        }

        $pit = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($to, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($pit as $file) {
            \assert($file instanceof \SplFileInfo);
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($to))), '/');
            if ($file->isFile() && !isset($kept[$rel])) {
                @unlink($file->getPathname());
            } elseif ($file->isDir() && (glob($file->getPathname() . '/*') ?: []) === []) {
                @rmdir($file->getPathname());
            }
        }
    }


    /**
     * @return array<string, mixed>
     */
    private function pageContext(Site $site, Content $content, string $ogType): array
    {
        return [
            'title' => $content->title . ' — ' . $site->name,
            'seo_title' => $content->seoTitle !== '' ? $content->seoTitle : $content->title . ' — ' . $site->name,
            // seo_description → excerpt → the site default. The last step keeps
            // Lighthouse's "document does not have a meta description" off pages
            // that were created with neither.
            'seo_description' => $content->seoDescription !== ''
                ? $content->seoDescription
                : ($content->excerpt !== '' ? $content->excerpt : $site->metaDescription),
            'canonical' => $this->url($site, $this->pagePath($site, $content)),
            'og_type' => $ogType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Site $site, Content $content, string $prefix = ''): array
    {
        return [
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'slug' => $content->slug,
            'url' => $prefix . $this->pagePath($site, $content),
            'published_at' => $content->publishedAt,
            'author_name' => $content->authorName,
            'featured_thumb' => $content->featuredMediaId !== null
                ? $this->mediaRenderer->thumbnail($site->handle, $content->featuredMediaId)
                : '',
        ];
    }

    private function pagePath(Site $site, Content $content): string
    {
        $pattern = $content->type === 'post' ? $site->postPathPattern : $site->pagePathPattern;

        return str_replace('{slug}', $content->slug, $pattern);
    }

    private function outputPath(string $pattern, string $slug): string
    {
        return trim(str_replace('{slug}', $slug, $pattern), '/') . '/index.html';
    }

    private function url(Site $site, string $path): string
    {
        return $site->baseUrl !== '' ? $site->baseUrl . $path : $path;
    }

    /**
     * Flags for JSON-LD emitted into a <script> block: HEX_TAG/HEX_AMP keep
     * a literal "</script>" in any string field from terminating the raw-text
     * script element (stored-XSS vector — titles are not HTML-sanitized).
     */
    private const JSON_LD_FLAGS = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * The stored json_ld column only has a json_valid CHECK — re-encode it
     * with the safe flags before it goes out through |raw.
     */
    private function safeStoredJsonLd(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return json_encode($decoded, self::JSON_LD_FLAGS);
    }

    private function websiteJsonLd(Site $site): string
    {
        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site->name,
            'url' => $this->url($site, '/'),
        ], self::JSON_LD_FLAGS);
    }

    private function articleJsonLd(Site $site, Content $post): string
    {
        return $this->articleJsonLdFor($site, $post->title, $this->url($site, $this->pagePath($site, $post)), $post->publishedAt, $post->updatedAt, $post->authorName, $post->excerpt);
    }

    private function articleJsonLdFor(Site $site, string $title, string $url, ?string $published, string $modified, string $author, string $excerpt): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'url' => $url,
            'datePublished' => $published,
            'dateModified' => $modified,
        ];
        if ($author !== '') {
            $data['author'] = ['@type' => 'Person', 'name' => $author];
        }
        if ($excerpt !== '') {
            $data['description'] = $excerpt;
        }

        return json_encode($data, self::JSON_LD_FLAGS);
    }

    /**
     * Per-locale sitemap listing that locale's rendered URLs.
     *
     * @param list<array<string, mixed>> $localePosts
     * @param list<array<string, mixed>> $localePages
     */
    private function localeSitemap(Site $site, string $prefix, array $localePosts, array $localePages): string
    {
        $entries = [
            ['loc' => $this->url($site, $prefix . '/'), 'lastmod' => null],
            ['loc' => $this->url($site, $prefix . '/blog/'), 'lastmod' => null],
        ];
        foreach ([...$localePosts, ...$localePages] as $entry) {
            $entries[] = ['loc' => $this->url($site, (string) $entry['card']['url']), 'lastmod' => null];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>' . htmlspecialchars((string) $entry['loc'], ENT_XML1) . '</loc></url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * @param list<string> $locales
     */
    private function sitemapIndex(Site $site, array $locales): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($locales as $locale) {
            $xml .= '  <sitemap><loc>' . htmlspecialchars($this->url($site, '/sitemap-' . $locale . '.xml'), ENT_XML1) . '</loc></sitemap>' . "\n";
        }

        return $xml . '</sitemapindex>' . "\n";
    }

    private function robots(Site $site): string
    {
        $out = $this->robotsBanner($site) . "\n\n";
        $out .= "User-agent: *\nAllow: /\n";
        if ($site->baseUrl !== '') {
            $out .= "\nSitemap: " . $site->baseUrl . "/sitemap.xml\n";
            $out .= '# LLM guide: ' . $site->baseUrl . "/llms.txt\n";
        }

        return $out;
    }

    /**
     * A FIGlet ASCII banner in a source-comment block, prepended to robots.txt
     * (§task 4). Printable ASCII only, ≤78 cols, centered.
     */
    private function robotsBanner(Site $site): string
    {
        $inner = 74; // '# ' + inner + ' #' = 78
        $border = str_repeat('#', 78);
        $host = $site->baseUrl !== '' ? (parse_url($site->baseUrl, PHP_URL_HOST) ?: '') : '';
        $byline = $site->name . ' - published as fully static HTML by fastsite';

        $rows = ['', ...$this->figlet->renderCentered($site->name, $inner), '', self::centerText($byline, $inner)];
        if ($host !== '') {
            $rows[] = self::centerText($host, $inner);
        }
        $rows[] = '';

        $lines = [$border];
        foreach ($rows as $row) {
            $lines[] = '# ' . str_pad(substr($row, 0, $inner), $inner) . ' #';
        }
        $lines[] = $border;

        return implode("\n", $lines);
    }

    private static function centerText(string $s, int $width): string
    {
        // Collapse every non-printable-ASCII byte (control chars, newlines,
        // multibyte) to a single space FIRST — otherwise a non-ASCII site
        // name (e.g. "København") ships raw bytes into robots.txt and, since
        // substr/str_pad count bytes not columns, misaligns the box border.
        // After this, strlen == columns, so the width math is exact.
        $s = (string) preg_replace('/[^\x20-\x7E]/', ' ', $s);
        $s = substr($s, 0, $width);

        return str_pad($s, $width, ' ', STR_PAD_BOTH);
    }

    /**
     * llms.txt (llmstxt.org shape): H1 + summary + a flat list of the site's
     * published pages/posts as apex-absolute links, for LLM consumption.
     *
     * @param list<array<string, mixed>> $postCards
     * @param list<array<string, mixed>> $pageCards
     */
    private function llmsTxt(Site $site, array $postCards, array $pageCards): string
    {
        // Content-derived text (site name, titles, excerpts) is only trimmed
        // on ingest, so it can carry interior newlines/control chars. Collapse
        // them to single spaces so an injected "\n## Heading" can't fabricate
        // sections or list items in this line-oriented file.
        $flat = static fn (string $s): string => trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));

        $out = '# ' . $flat($site->name) . "\n\n";
        $out .= '> ' . $flat($site->name) . ' — a fully static, fast-loading site. The pages and posts below are the site\'s primary content.' . "\n";

        $section = function (string $heading, array $cards) use ($site, $flat): string {
            if ($cards === []) {
                return '';
            }
            $block = "\n## " . $heading . "\n\n";
            foreach ($cards as $card) {
                // Escape ] so a bracket in a title can't break the link text.
                $title = str_replace(']', '\\]', $flat((string) ($card['title'] ?? ''))) ?: 'Untitled';
                $url = $this->url($site, (string) ($card['url'] ?? '/'));
                $excerpt = $flat((string) ($card['excerpt'] ?? ''));
                $block .= '- [' . $title . '](' . $url . ')' . ($excerpt !== '' ? ': ' . $excerpt : '') . "\n";
            }

            return $block;
        };

        $out .= $section('Pages', $pageCards);
        $out .= $section('Posts', $postCards);

        return $out;
    }

    private function headers(string $scriptMode = 'none'): string
    {
        // 'none'   = zero-JS (default). 'inline' = only the lang-redirect inline
        // script. 'full' = user scripts opted in (self + inline + https + blob:
        // — embed loaders commonly boot their payload from a blob: URL, which
        // only a script the policy already allowed can have created).
        $csp = match ($scriptMode) {
            'full' => "script-src 'self' 'unsafe-inline' https: blob:; frame-ancestors 'self'",
            'inline' => "script-src 'unsafe-inline'; frame-ancestors 'self'",
            default => "script-src 'none'; frame-ancestors 'self'",
        };

        return <<<TXT
            /*
              X-Content-Type-Options: nosniff
              Referrer-Policy: strict-origin-when-cross-origin
              Content-Security-Policy: {$csp}
            /assets/*
              Cache-Control: public, max-age=31536000, immutable
            /media/*
              Cache-Control: public, max-age=31536000, immutable
            /files/*
              Cache-Control: public, max-age=31536000, immutable

            TXT;
    }

    /**
     * Cloudflare Pages evaluates _redirects BEFORE static assets, so a rule
     * whose from_path is a live page would shadow it — filter those out.
     *
     * @param list<string> $outputKeys e.g. "blog/x/index.html"
     */
    private function redirectsFile(Site $site, array $outputKeys): string
    {
        $livePaths = [];
        foreach ($outputKeys as $key) {
            $livePaths[$key === 'index.html' ? '/' : (str_ends_with($key, '/index.html') ? '/' . substr($key, 0, -10) : '/' . $key)] = true;
        }

        $rows = $this->databases->site($site->handle)->fetchAllAssociative(
            'SELECT from_path, to_path, status FROM redirects ORDER BY from_path',
        );

        // NOTE: www→apex is NOT emitted here. Cloudflare Pages `_redirects`
        // matches the request PATH only (never scheme/host) and rejects the
        // Netlify `!` forced-status flag, so a `https://www…/*` source is a
        // dead line. The apex `<link rel=canonical>` (from normalizeBaseUrl)
        // is the mechanism Google honors for www/apex dedup; a host-level
        // www→apex 301 belongs in a Cloudflare zone Redirect Rule (operator).
        $lines = [];

        foreach ($rows as $row) {
            $from = (string) $row['from_path'];
            if (isset($livePaths[$from])) {
                $this->logger->warning('Redirect shadows a live page — omitted from _redirects', ['site' => $site->handle, 'from' => $from]);
                continue;
            }
            $lines[] = sprintf('%s %s %d', $from, $row['to_path'], $row['status']);
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Writes outputs through the build_manifest: unchanged files skipped,
     * orphans deleted.
     *
     * @param array<string, string> $outputs path => content
     * @return array{int, int, int, int} [written, skipped, deleted, bytes]
     */
    private function writeOutputs(Site $site, string $outDir, array $outputs): array
    {
        $db = $this->databases->site($site->handle);

        /** @var array<string, string> $manifest path => rendered_sha256 */
        $manifest = array_column(
            $db->fetchAllAssociative('SELECT output_path, rendered_sha256 FROM build_manifest'),
            'rendered_sha256',
            'output_path',
        );

        $written = 0;
        $skipped = 0;
        $bytes = 0;
        $now = $this->clock->iso();

        foreach ($outputs as $path => $content) {
            $bytes += \strlen($content);
            $hash = hash('sha256', $content);
            $target = $outDir . '/' . $path;

            if (($manifest[$path] ?? null) === $hash && is_file($target)) {
                ++$skipped;
                unset($manifest[$path]);
                continue;
            }

            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create build directory: ' . $dir);
            }
            // Manifest row is only recorded after a verified complete write —
            // a partial write (disk full) must not be skipped as "unchanged".
            if (file_put_contents($target, $content) !== \strlen($content)) {
                throw new \RuntimeException('Short write on build output: ' . $target);
            }

            $db->executeStatement(
                'INSERT INTO build_manifest (output_path, rendered_sha256, updated_at)
                 VALUES (?, ?, ?)
                 ON CONFLICT (output_path) DO UPDATE
                 SET rendered_sha256 = excluded.rendered_sha256, updated_at = excluded.updated_at',
                [$path, $hash, $now],
            );

            ++$written;
            unset($manifest[$path]);
        }

        // Whatever is left in $manifest was built before but not this time.
        $deleted = 0;
        foreach (array_keys($manifest) as $orphan) {
            $file = $outDir . '/' . $orphan;
            if (is_file($file) && !unlink($file)) {
                // Keep the manifest row so the orphan is retried next build
                // (Windows sharing violations make this a real path).
                $this->logger->warning('Could not delete orphan build file', ['file' => $file]);
                continue;
            }
            $db->executeStatement('DELETE FROM build_manifest WHERE output_path = ?', [$orphan]);
            ++$deleted;
        }

        Dirs::pruneEmpty($outDir);

        return [$written, $skipped, $deleted, $bytes];
    }
}
