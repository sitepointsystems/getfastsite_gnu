<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Doctrine\DBAL\Connection;
use Fastsite\Build\Dto\BuildStats;
use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Fs\Dirs;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Service\RedirectService;
use MatthiasMullie\Minify\CSS as CssMinifier;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
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

    /**
     * How many per-page CSP rules `_headers` may carry before the build gives
     * up and relaxes site-wide instead.
     *
     * Cloudflare Pages documents a 100-rule ceiling for `_headers`. 60 leaves
     * room for the four rules the file always carries plus whatever is added
     * later, and a targeted page costs one or two rules PER LOCALE — so three
     * targeted pages on a nine-locale site already spends about half of this.
     * Exceeding the real limit would make CF drop rules silently, leaving a
     * page shipping a script its own policy forbids: a break visible only in a
     * console nobody opens. Relaxing site-wide with a build WARNING is the
     * worse policy but the honest failure.
     */
    private const MAX_HEADER_RULES = 60;

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
        private readonly HtaccessBuilder $htaccess,
        private readonly \Fastsite\Component\Service\ComponentHookService $componentHooks,
        private readonly \Fastsite\Form\Service\FormRenderer $formRenderer,
        // Defaulted to the real repository rather than left null: the build
        // has the database manager and the clock already, so there is no
        // wiring for php-di to silently skip — a null here would mean a site
        // with categories building without its archives and nobody noticing.
        ?\Fastsite\Category\Repository\CategoryRepository $categories = null,
        // Same reasoning as $categories directly above: defaulted and built
        // from deps this constructor already holds, so there is no wiring for
        // php-di to silently skip AND the twenty places that construct a
        // BuildService by hand keep working untouched.
        ?PageAuditLog $auditLog = null,
    ) {
        $this->categories = $categories ?? new \Fastsite\Category\Repository\DbalCategoryRepository($databases, $clock);
        $this->auditLog = $auditLog ?? new PageAuditLog($databases, $clock);
    }

    private readonly \Fastsite\Category\Repository\CategoryRepository $categories;
    private readonly PageAuditLog $auditLog;

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
     * Scroll-spy for a shell-rendered table of contents: highlights the TOC
     * link of the last heading scrolled past by toggling class `fs-active` +
     * `aria-current="true"` on the matching anchor inside `[data-fs-toc]`.
     * Injected ONLY into pages whose shell rendered such a nav (opt-in, like
     * the language switcher), wrapped in data-fs-embed so PageAudit exempts
     * it, and served under the same inline-only CSP the language scripts use.
     * Fails silent: no nav, no IntersectionObserver, or no resolvable anchors
     * → nothing happens.
     */
    private function injectTocSpy(string $html): string
    {
        // Belt and braces: a passive scroll listener AND a rAF watcher that
        // recomputes only when the scroll position changed. Scroll events are
        // swallowed by some embedded webviews (where rAF still ticks), and an
        // IntersectionObserver band would miss instant jumps (anchor clicks,
        // keyboard End) that teleport past it. rAF auto-pauses on hidden
        // tabs; its idle cost is one property read per frame.
        $snippet = '<div data-fs-embed style="display:none"><script>(function(){try{'
            . 'var n=document.querySelector("[data-fs-toc]");if(!n)return;'
            . 'var cur=null,map={},hs=[];'
            . 'function set(a){if(a===cur)return;if(cur){cur.classList.remove("fs-active");cur.removeAttribute("aria-current");}'
            . 'if(a){a.classList.add("fs-active");a.setAttribute("aria-current","true");}cur=a;}'
            . '[].forEach.call(n.querySelectorAll(\'a[href^="#"]\'),function(a){'
            . 'var h=document.getElementById(decodeURIComponent(a.getAttribute("href").slice(1)));'
            . 'if(h){map[h.id]=a;hs.push(h);}});'
            . 'if(!hs.length)return;'
            . 'var last=NaN;'
            . 'function pick(){last=window.pageYOffset;var t=null;for(var i=0;i<hs.length;i++){'
            . 'if(hs[i].getBoundingClientRect().top<=140)t=hs[i];}'
            . 'set(t?map[t.id]:null);}'
            . 'function loop(){if(window.pageYOffset!==last){pick();}requestAnimationFrame(loop);}'
            . 'addEventListener("scroll",pick,{passive:true});'
            . 'requestAnimationFrame(loop);pick();'
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
            $nav = LangSwitcher::render($links, $current, $flagUrls, $site->ctaTracking);
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
     * Emits a page's robots directives ('noindex', 'nofollow', or both) as a
     * head <meta> — injected into the rendered HTML so EVERY shell honors the
     * flags without a template change. No directives = no tag.
     */
    private function injectRobotsMeta(string $html, string $directives): string
    {
        if ($directives === '') {
            return $html;
        }

        return str_ireplace('</head>', '<meta name="robots" content="' . $directives . '"></head>', $html);
    }

    /**
     * The site-wide development directive: `noindex, nofollow` on one page.
     *
     * Any robots meta already on the page is REPLACED rather than joined —
     * development mode is strictly more restrictive than anything a content
     * flag or a shell could have asked for, and shipping two robots tags is a
     * contradiction a reader has to resolve. Matching is attribute-order
     * independent, because `<meta content="…" name="robots">` is just as valid
     * as the usual order and would otherwise survive.
     */
    private static function injectSiteNoindex(string $html): string
    {
        $html = (string) preg_replace('#<meta\b[^>]*\bname\s*=\s*["\']?robots["\']?[^>]*>#i', '', $html);

        return str_ireplace('</head>', '<meta name="robots" content="noindex, nofollow"></head>', $html);
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
     * @return array<string, mixed>
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
        //
        // Returns the intrinsic size alongside the URL: shells can size a logo
        // without CLS, and the JSON-LD Organization logo needs real dimensions.
        $pick = static function (?int $id, ?int $target) use ($loaded, &$usedMedia): ?array {
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

            return [
                'url' => '/media/' . $id . '/' . basename($chosen->diskPath),
                'width' => $chosen->width,
                'height' => $chosen->height,
                'alt' => $loaded[$id]->alt,
            ];
        };

        // The favicon canNOT be picked by size the way a logo is.
        //
        // Google Search supports BMP, GIF, ICO, PNG, JPEG, PPM and TIFF —
        // avif and webp are not on that list. The worker emits avif+webp at
        // every width and a jpg/png fallback only at the LARGEST, so "the
        // smallest responsive variant" was always an avif: a favicon Google
        // could never read, failing silently in the one place nobody looks.
        // Prefer png (keeps transparency), else jpg.
        $out = [];
        $faviconId = $site->faviconMediaId;
        if ($faviconId !== null && isset($loaded[$faviconId])) {
            $usable = array_values(array_filter(
                $loaded[$faviconId]->variants,
                static fn ($v): bool => $v->purpose === 'responsive' && \in_array($v->format, ['png', 'jpg'], true),
            ));
            if ($usable !== []) {
                usort($usable, static fn ($a, $b): int => $a->width <=> $b->width);
                $chosen = $usable[0];
                $usedMedia[$faviconId] = true;
                $out['favicon_url'] = '/media/' . $faviconId . '/' . basename($chosen->diskPath);
                $out['favicon_type'] = $chosen->format === 'png' ? 'image/png' : 'image/jpeg';
                $out['favicon_width'] = $chosen->width;
                $out['favicon_height'] = $chosen->height;
            }
        }
        if (($v = $pick($site->logoHeaderMediaId, $site->logoMaxWidth)) !== null) {
            $out['logo_header_url'] = $v['url'];
            $out['logo_header_width'] = $v['width'];
            $out['logo_header_height'] = $v['height'];
            $out['logo_header_alt'] = $v['alt'];
        }
        if (($v = $pick($site->logoFooterMediaId, $site->logoMaxWidth)) !== null) {
            $out['logo_footer_url'] = $v['url'];
            $out['logo_footer_width'] = $v['width'];
            $out['logo_footer_height'] = $v['height'];
            $out['logo_footer_alt'] = $v['alt'];
        }

        // The site's DEFAULT share card — the fallback for every page that has
        // no featured image of its own. Same selection as a page's own card,
        // through the one helper, so the two can never drift apart.
        $id = $site->socialImageMediaId;
        if ($id !== null && isset($loaded[$id]) && ($card = $this->shareCard($site, (int) $id, $loaded[$id])) !== null) {
            $usedMedia[$id] = true;
            $out['social_image_url'] = $card['url'];
            $out['social_image_width'] = $card['width'];
            $out['social_image_height'] = $card['height'];
            $out['social_image_alt'] = $card['alt'];
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

            $audit = new PageAudit(false, self::canonicalHostOf($site));
            $audit->auditCss($inlineCss);

            $posts = \array_slice($this->content->listPublished($site->handle, 'post'), 0, 1);
            $pages = \array_slice($this->content->listPublished($site->handle, 'page'), 0, 1);
            $postCards = array_map(fn (Content $c): array => $this->card($site, $c), $posts);

            // Structured data without the branding lookup — this is a dry run
            // over the SHELL, and a template that mishandles `json_ld` must
            // still be caught here rather than at build time.
            $jsonLd = $this->jsonLdBuilder($site, []);

            $samples = [];
            $samples['index.html'] = $this->renderPage($twig, 'home.html.twig', [
                'page' => ['title' => $site->name, 'seo_title' => $site->name, 'seo_description' => 'x', 'canonical' => $this->url($site, '/'), 'og_type' => 'website'],
                'content' => null,
                'posts' => $postCards,
                'json_ld' => $jsonLd?->graph(kind: 'home', url: $this->url($site, '/'), title: $site->name, locale: $site->primaryLocale),
            ]);
            $samples['blog/index.html'] = $this->renderPage($twig, 'blog-index.html.twig', [
                'page' => ['title' => 'Blog', 'seo_title' => 'Blog', 'seo_description' => 'x', 'canonical' => $this->url($site, '/blog/'), 'og_type' => 'website'],
                'posts' => $postCards,
                'json_ld' => null,
            ]);
            // `content` is null here on purpose: the dry-run has to prove the
            // shell still renders for a site with NO error row, which is the
            // fallback branch every existing site takes. A template that reads
            // content.body_html unguarded fails here rather than at publish.
            $samples['404.html'] = $this->renderPage($twig, '404.html.twig', [
                'page' => ['title' => 'x', 'seo_title' => 'x', 'seo_description' => 'x', 'canonical' => '', 'og_type' => 'website'],
                'content' => null,
                'json_ld' => null,
            ]);
            // A category archive, with a full pagination block, so a template
            // that reads category/pagination wrongly fails HERE and not at the
            // first publish after someone creates a category.
            if ($twig->getLoader()->exists('category.html.twig')) {
                $samples['category/x/index.html'] = $this->renderPage($twig, 'category.html.twig', [
                    'page' => $this->simplePage('x', $this->url($site, '/category/x/'), [], 'x') + ['breadcrumb' => [['name' => 'Home', 'url' => '/'], ['name' => 'x', 'url' => null]]],
                    'posts' => $postCards,
                    'category' => ['name' => 'x', 'slug' => 'x', 'url' => '/category/x/', 'description_html' => '<p>x</p>', 'post_count' => \count($postCards)],
                    'pagination' => [
                        'current' => 1, 'total' => 2, 'prev_url' => null, 'next_url' => '/category/x/page/2/',
                        'pages' => [['number' => 1, 'url' => '/category/x/', 'current' => true], ['number' => 2, 'url' => '/category/x/page/2/', 'current' => false]],
                    ],
                    'json_ld' => null,
                ]);
            }
            if ($twig->getLoader()->exists('error.html.twig')) {
                $samples['error.html'] = $this->renderPage($twig, 'error.html.twig', [
                    'page' => ['title' => 'x', 'seo_title' => 'x', 'seo_description' => 'x', 'canonical' => '', 'og_type' => 'website', 'error_status' => 403],
                    'content' => null,
                    'json_ld' => null,
                ]);
            }
            foreach ($posts as $post) {
                [$content] = $this->renderContent($site, $post);
                $samples['post.html'] = $this->renderPage($twig, 'post.html.twig', [
                    'page' => $this->pageContext($site, $post, 'article') + ['preload' => null],
                    'content' => $content,
                    'json_ld' => $jsonLd?->graph(
                        kind: 'post',
                        url: $this->url($site, $this->pagePath($site, $post)),
                        title: $post->title,
                        locale: $site->primaryLocale,
                        description: $post->excerpt,
                        published: $post->publishedAt,
                        modified: $post->updatedAt,
                        author: $post->authorName,
                        stored: $post->jsonLd,
                    ),
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
    /**
     * @param bool $force re-write every output instead of skipping the ones
     *                    whose rendered bytes match build_manifest. Set for
     *                    anything a person deliberately asked for (a publish
     *                    from the dashboard, MCP, REST or the CLI): the point
     *                    of pressing rebuild is to get a rebuild, and a build
     *                    that reports "0 written" because it trusted its own
     *                    bookkeeping is indistinguishable from one that failed
     *                    quietly. Background triggers stay incremental.
     */
    public function build(Site $site, ?\Closure $tick = null, bool $force = false): BuildStats
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

        // Error pages are content rows (`content.error_status`) but they are
        // NOT ordinary pages: each renders to the fixed `/{status}.html` its
        // serving mode looks for, never to its slug path. Partitioned out here
        // so they reach none of the machinery that assumes a public URL — the
        // pages loop, sitemap-*.xml, llms.txt, the hreflang cluster, the blog
        // index. Same shape as the home-page extraction below, and for the
        // same reason: a row whose output path is special has to leave the
        // general list before anything iterates it.
        //
        // Keyed by status, which is also how the build asks for them.
        /** @var array<int, \Fastsite\Content\Dto\Content> $errorPages */
        $errorPages = [];
        $pages = array_values(array_filter($pages, static function ($p) use (&$errorPages): bool {
            if ($p->errorStatus === null) {
                return true;
            }
            $errorPages[$p->errorStatus] = $p;

            return false;
        }));

        // Component hooks: site-level "insert this CTA into these pages here"
        // rules, applied per page body before expansion (HookInjector). A
        // failure to load them degrades to no injection, never a dead build.
        try {
            $hooks = $this->componentHooks->list($site->handle, true);
        } catch (\Throwable $e) {
            $hooks = [];
            $this->logger->warning('Component hooks unavailable — skipped', ['site' => $site->handle, 'error' => $e->getMessage()]);
        }

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
        // The clusters need every locale up front but ONLY the slugs; the
        // full rows (translated bodies included) load per locale inside the
        // loop and are freed with it, so a ten-locale site never holds ten
        // locales of bodies at once.
        $locales = $this->buildLocales($site);
        $slugsByLocale = [];
        foreach ($locales as $locale) {
            $slugsByLocale[$locale] = $locale === $site->primaryLocale
                ? []
                : $this->translations->usableSlugsByLocale($site->handle, $locale);
        }
        // Categories by id. A post's category slug is already on its row (the
        // repository resolves it), but the archives and crumbs need names.
        /** @var array<int, \Fastsite\Category\Dto\Category> $categories */
        $categories = [];
        foreach ($this->categories->all($site->handle) as $category) {
            $categories[$category->id] = $category;
        }
        $altLinks = $this->alternateLinks($site, $locales, $posts, $pages, $slugsByLocale, $categories);

        /** @var array<int, true> $usedMedia media ids referenced anywhere this build */
        $usedMedia = [];
        /** @var array<int, true> $usedFiles file ids referenced anywhere this build */
        $usedFiles = [];
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

        // Structured data (schema.org JSON-LD). One builder for the whole build
        // so every page of every locale points at the SAME organization and
        // website entity, and one batched image lookup for every featured image
        // the graph will reference.
        $jsonLd = $this->jsonLdBuilder($site, $branding);
        // Loaded UNCONDITIONALLY, which is the point: a page share card comes
        // out of the same batched lookup, and gating it on `$jsonLd` would mean
        // a site with `structured_data` switched off silently lost every
        // per-page share card with nothing warning anyone. The JSON-LD half
        // stays gated exactly as before. Error rows are included because they
        // are partitioned out of `$pages` and can carry a featured image too.
        $featuredMedia = $this->featuredImageMaps($site, [...$posts, ...$pages, ...array_values($errorPages)]);
        $featuredImages = $jsonLd !== null ? $featuredMedia['ld'] : [];
        $shareCards = $featuredMedia['share'];
        // The site's default card, resolved once: the fallback the share-card
        // injection applies to every page that records none of its own — the
        // home page without a featured image, the blog index, a shell-rendered
        // 404. Null when the site has no `social_image_media_id` either, and
        // then a page with no featured image simply carries no card, exactly
        // as before.
        $siteCard = self::cardForPage([], $branding);
        // User scripts + the audit are set up AHEAD of rendering: the build
        // streams — each locale is finalized, audited and WRITTEN before the
        // next one renders — so everything a finalized page needs must
        // already exist.
        //
        // Loaded as ROWS, not as one rendered bundle: a script now chooses its
        // pages, so the bundle is per page. `$scriptsFor` memoises by
        // (type, slug) — a 200-post site renders the bundle once per distinct
        // target combination, not two hundred times.
        $scriptRows = $this->siteScripts->list($site->handle, true);
        $hasScripts = $scriptRows !== [];
        /** @var array<string, array{head: string, body_open: string, body_end: string}> $scriptCache */
        $scriptCache = [];
        $scriptsFor = static function (string $type, string $slug) use ($scriptRows, &$scriptCache): array {
            $key = $type . '|' . $slug;
            /** @var array<string, array{head: string, body_open: string, body_end: string}> $scriptCache */
            if (!isset($scriptCache[$key])) {
                $scriptCache[$key] = \Fastsite\Site\Service\SiteScriptService::renderFor($scriptRows, $type, $slug);
            }

            return $scriptCache[$key];
        };

        // The site-wide blocks, for the audit's exemption list: every block any
        // script could inject anywhere. The audit's job is "is this markup
        // allowed to be here", and a block is allowed on whichever pages its
        // own rule put it on — so the exemption list is the union, while the
        // per-page CSP below is exact.
        $allScriptBlocks = array_values($this->siteScripts->renderByLocation($site->handle));

        // PageSpeed-100 audit over every emitted page + the inlined CSS. When a
        // site ships user scripts, the zero-JS FAIL is relaxed for that site and
        // the injected blocks themselves are cut out before the asset checks —
        // a tag pack is trusted, opted-in markup, and auditing its innards
        // (GTM ships a <noscript><iframe>) failed every page of the site.
        // FAIL = pipeline invariant → the build throws BEFORE the failing
        // locale is written; WARN = content smell, logged at the end.
        $audit = new PageAudit(
            $hasScripts,
            self::canonicalHostOf($site),
            $allScriptBlocks,
            $site->ctaTracking,
            $site->development,
            array_intersect_key($branding, array_flip(['favicon_url', 'favicon_type', 'favicon_width', 'favicon_height'])) === []
                ? []
                : [
                    'url' => (string) ($branding['favicon_url'] ?? ''),
                    'type' => (string) ($branding['favicon_type'] ?? ''),
                    'width' => (int) ($branding['favicon_width'] ?? 0),
                    'height' => (int) ($branding['favicon_height'] ?? 0),
                ],
        );
        $audit->auditSite();
        $audit->auditCss($inlineCss);

        // Streaming write state. The manifest loads once; every batch consumes
        // the rows it re-emits, and whatever is left at the end is an orphan.
        $db = $this->databases->site($site->handle);
        /** @var array<string, string> $manifest path => rendered_sha256 */
        $manifest = array_column(
            $db->fetchAllAssociative('SELECT output_path, rendered_sha256 FROM build_manifest'),
            'rendered_sha256',
            'output_path',
        );
        /** @var array<string, true> $seenPaths every output path emitted this build — cross-locale collision guard + the page count */
        $seenPaths = [];
        $written = 0;
        $skipped = 0;
        $bytes = 0;
        $hasToc = false;
        // Highest CSP demand of the TRUSTED scripts the expansion chain
        // shipped (verbatim components, file-library widgets, verbatim
        // content): 0 = none, 1 = inline, 2 = external/self. Decides
        // `_headers` — shipping a script the CSP then blocks is a silent
        // break only visible in the browser console.
        $embedScriptLevel = 0;

        // Every output path's (type, PRIMARY slug), so a targeted script can be
        // matched per page. Primary, not translated: one rule has to cover
        // every locale's variant of a page, exactly as component hooks do.
        //
        // Pages with NO content row get a pseudo-type — `index` for the blog
        // index, `error` for a 404. `SiteScript::appliesTo` rejects an unknown
        // type from `posts`/`pages` and accepts it under `all`, so they need no
        // special case and `target: all` keeps meaning every page.
        /** @var array<string, array{0: string, 1: string}> $pageTargets */
        $pageTargets = [];
        /** @var array<int, array{0: string, 1: string}> $primaryMeta content id → type + primary slug */
        $primaryMeta = [];
        foreach ($posts as $p) {
            $primaryMeta[$p->id] = ['post', $p->slug];
        }
        foreach ($pages as $p) {
            $primaryMeta[$p->id] = ['page', $p->slug];
        }

        // Each page's own CSP demand, accumulated across locales and resolved
        // into `_headers` after the loop. Kept across locales deliberately —
        // the rendered pages are freed per locale, this map is one small int
        // per path.
        /** @var array<string, int> $pageScriptMode path → 0 none / 1 inline / 2 full */
        $pageScriptMode = [];

        foreach ($locales as $locale) {
            $twig = $this->twig($site, $shellPath, $locale, $branding);
            $twig->addGlobal('inline_css', $inlineCss);
            $prefix = $this->localePrefix($site, $locale);

            // Locale-scoped page state: this locale renders, finalizes and
            // writes before the next begins (see the end of this loop).
            /** @var array<string, string> $outputs path → rendered page */
            $outputs = [];
            /** @var array<string, string> $pageJsonLd output path → encoded graph */
            $pageJsonLd = [];
            /** @var array<string, string> $pagePreloads output path → the page's LCP preload link */
            $pagePreloads = [];
            /** @var array<string, array{url: string, width: int, height: int, alt: string}|null> $pageShareCards output path → its share card; absent = the site default */
            $pageShareCards = [];
            /** @var list<string> $lcpWarnPaths primary-locale content pages with images but no prioritised LCP image */
            $lcpWarnPaths = [];
            // Per-output hreflang cluster (same data the <head> alternates use)
            // so the language switcher links the REAL locale variants —
            // translated slugs included, which a same-path guess across trees
            // would miss.
            /** @var array<string, list<array{hreflang: string, href: string}>> $pageAlternates */
            $pageAlternates = [];

            // Breadcrumb labels come from the shell's own i18n strings, so a
            // Danish page's trail reads the way its nav does rather than
            // shipping "Home" into every locale's structured data.
            $crumbStrings = $locale === $site->primaryLocale
                ? $this->localeStrings($shellPath, $locale)
                : $this->localeStrings($shellPath, $locale) + $this->localeStrings($shellPath, $site->primaryLocale);
            $crumbLabels = [
                'home' => (string) ($crumbStrings['home'] ?? 'Home'),
                'blog' => (string) ($crumbStrings['blog'] ?? 'Blog'),
                // "Page" in an archive's title from page 2 on.
                'page' => (string) ($crumbStrings['page'] ?? 'Page'),
            ];
            $localeHomeUrl = $this->url($site, $prefix . '/');
            $localeBlogUrl = $this->url($site, $prefix . '/blog/');

            // Full translation rows (bodies included) for THIS locale only —
            // loaded here, freed before the next locale renders.
            $localeTranslations = $locale === $site->primaryLocale
                ? []
                : $this->translations->usableByLocale($site->handle, $locale);
            $localePosts = $this->localizeList($posts, $locale, $localeTranslations, $site, $jsonLd, $featuredImages, $crumbLabels, $hooks, $shareCards, $categories);
            $localePages = $this->localizeList($pages, $locale, $localeTranslations, $site, $jsonLd, $featuredImages, $crumbLabels, $hooks, $shareCards, $categories);
            // Rendered HERE, not down with the other site-level outputs, because
            // the translation rows are freed on the next line — a locale's
            // bodies are the single biggest thing this loop holds.
            $errorOut = $this->renderErrorPages($twig, $site, $errorPages, $locale, $localeTranslations, $hooks, $prefix, $shareCards);
            unset($localeTranslations);
            // The expansion chain above (components/media/files per item) is a
            // locale's slowest tick-free stretch — pulse before rendering.
            $tick();

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
                // llms.txt is a crawler-facing index — noindex'd content stays
                // out of it (the on-site blog listing still shows every post).
                $indexable = static fn (array $e): bool => !$e['noindex'];
                $primaryPostCards = array_map(fn (array $c): array => $c['card'], array_values(array_filter($localePosts, $indexable)));
                $primaryPageCards = array_map(fn (array $c): array => $c['card'], array_values(array_filter($localePages, $indexable)));
            }

            // Engine-owned paths are written FIRST and reserved, so a stray
            // content page can never silently clobber a locale home/index.
            $homePath = trim($prefix, '/') === '' ? 'index.html' : trim($prefix, '/') . '/index.html';
            $this->reserveOutput($outputs, $homePath, $site, $seenPaths);

            // The home graph is rebuilt here rather than reused from the entry:
            // home-as-content lives at /home/ in the content model but RENDERS
            // at the locale root, and every @id in a graph derives from the
            // canonical URL. Every locale gets its own (inLanguage + its own
            // canonical) — previously only the primary carried any at all.
            $homeLd = $homeEntry !== null ? $homeEntry['ld'] : null;
            $homeGraph = $jsonLd?->graph(
                kind: 'home',
                url: $localeHomeUrl,
                title: $homeLd !== null ? (string) $homeLd['title'] : $site->name,
                locale: $locale,
                description: $homeLd !== null && $homeLd['description'] !== '' ? (string) $homeLd['description'] : $site->metaDescription,
                published: $homeLd !== null ? $homeLd['published'] : null,
                modified: $homeLd !== null ? $homeLd['modified'] : null,
                image: $homeLd !== null ? $homeLd['image'] : null,
                stored: $homeLd !== null ? $homeLd['stored'] : null,
            );
            if ($homeGraph !== null) {
                $pageJsonLd[$homePath] = $homeGraph;
            }

            if ($homeEntry !== null) {
                // The home page IS content — feed its rendered body to the home
                // template, but keep the canonical/hreflang anchored at the root.
                $usedMedia += array_fill_keys($homeEntry['media_ids'], true);
                $usedFiles += array_fill_keys($homeEntry['file_ids'], true);
                $homePageCtx = $homeEntry['page'];
                $homePageCtx['canonical'] = $this->url($site, $prefix . '/');
                $homePageCtx['og_type'] = 'website';
                $homePageCtx['hreflang'] = $altLinks['home'] ?? [];
                $outputs[$homePath] = $this->injectRobotsMeta($this->renderPage($twig, 'home.html.twig', [
                    'page' => $homePageCtx,
                    'content' => $homeEntry['content'],
                    'posts' => \array_slice($postCards, 0, 10),
                    'json_ld' => $homeGraph,
                ]), (string) $homeEntry['robots']);
                $this->collectLcp($homePath, $homeEntry, $locale === $site->primaryLocale, $pagePreloads, $lcpWarnPaths);
                $pageShareCards[$homePath] = self::cardForPage($homePageCtx, $branding);
            } else {
                $outputs[$homePath] = $this->renderPage($twig, 'home.html.twig', [
                    'page' => $this->simplePage($site->name, $this->url($site, $prefix . '/'), $altLinks['home'] ?? [], $site->metaDescription),
                    'content' => null,
                    'posts' => \array_slice($postCards, 0, 10),
                    'json_ld' => $homeGraph,
                ]);
            }

            $pageAlternates[$homePath] = $altLinks['home'] ?? [];
            // The home page is a `page` row whose primary slug is `home`
            // (HOME_SLUG) when one exists; with no row it is still a page in
            // every sense a targeting rule cares about, and `home` is the slug
            // the dashboard, MCP and hook docs all tell people to use.
            $pageTargets[$homePath] = $homeEntry !== null
                ? ($primaryMeta[$homeEntry['id']] ?? ['page', self::HOME_SLUG])
                : ['page', self::HOME_SLUG];

            $blogIndexPath = trim($prefix . '/blog', '/') . '/index.html';
            $this->reserveOutput($outputs, $blogIndexPath, $site, $seenPaths);

            // A listing page is a CollectionPage whose mainEntity is the list.
            // noindex'd posts stay OUT of it for the same reason they stay out
            // of the sitemap and llms.txt — the on-site listing still shows
            // them, but telling a crawler "here is a thing" and "do not index
            // that thing" in one breath is a contradictory signal.
            $blogItems = [];
            foreach ($localePosts as $entry) {
                if (!$entry['noindex']) {
                    $blogItems[] = ['name' => (string) $entry['card']['title'], 'url' => $this->url($site, (string) $entry['card']['url'])];
                }
            }
            $blogGraph = $jsonLd?->graph(
                kind: 'blog',
                url: $localeBlogUrl,
                title: $site->pageTitle('Blog'),
                locale: $locale,
                description: $site->metaDescription,
                breadcrumb: [
                    ['name' => $crumbLabels['home'], 'url' => $localeHomeUrl],
                    ['name' => $crumbLabels['blog'], 'url' => $localeBlogUrl],
                ],
                items: $blogItems,
            );
            if ($blogGraph !== null) {
                $pageJsonLd[$blogIndexPath] = $blogGraph;
            }

            $outputs[$blogIndexPath] = $this->renderPage($twig, 'blog-index.html.twig', [
                'page' => $this->simplePage($site->pageTitle('Blog'), $this->url($site, $prefix . '/blog/'), $altLinks['blog'] ?? [], $site->metaDescription),
                'posts' => $postCards,
                'json_ld' => $blogGraph,
            ]);
            // Pseudo-type: the blog index has no content row, so `posts` and
            // `pages` both skip it and only `target: all` reaches it — which
            // is right, and is why `all` must keep meaning literally every
            // page. A tag that missed the blog index would be a gap nobody
            // notices until a quarter of sessions are unaccounted for.
            $pageTargets[$blogIndexPath] = ['index', 'blog'];
            $pageAlternates[$blogIndexPath] = $altLinks['blog'] ?? [];

            // Category archives: engine-owned like the blog index, so they are
            // reserved BEFORE any content renders — a page that happens to sit
            // at /category/x/ loses to the archive, with a warning, rather than
            // the archive silently vanishing behind it.
            $archives = $this->renderArchives($twig, $site, $locale, $localePosts, $categories, $crumbLabels, $jsonLd, $altLinks['category']);
            foreach ($archives['outputs'] as $archivePath => $archiveHtml) {
                $this->reserveOutput($outputs, $archivePath, $site, $seenPaths);
                $outputs[$archivePath] = $archiveHtml;
                $pageTargets[$archivePath] = $archives['targets'][$archivePath];
                $pageAlternates[$archivePath] = $archives['alternates'][$archivePath] ?? [];
                if (isset($archives['json_ld'][$archivePath])) {
                    $pageJsonLd[$archivePath] = $archives['json_ld'][$archivePath];
                }
            }
            $archiveSitemap = $archives['sitemap'];
            unset($archives);

            foreach ($localePosts as $entry) {
                if ((++$rendered % 25) === 0) {
                    $tick();
                }
                // Read off the entry's own URL, which already went through the
                // post pattern WITH the category — rebuilding it here from the
                // pattern and the slug alone would drop the category segment.
                $path = trim((string) $entry['card']['url'], '/') . '/index.html';
                if (isset($outputs[$path]) || isset($seenPaths[$path])) {
                    $this->logger->warning('Output path collision — content skipped', ['site' => $site->handle, 'path' => $path]);
                    continue;
                }
                $usedMedia += array_fill_keys($entry['media_ids'], true);
                $usedFiles += array_fill_keys($entry['file_ids'], true);
                $outputs[$path] = $this->injectRobotsMeta($this->renderPage($twig, 'post.html.twig', [
                    'page' => $entry['page'] + ['hreflang' => $altLinks['content'][$entry['id']] ?? []],
                    'content' => $entry['content'],
                    'json_ld' => $entry['json_ld'],
                ]), (string) $entry['robots']);
                $this->collectLcp($path, $entry, $locale === $site->primaryLocale, $pagePreloads, $lcpWarnPaths);
                $pageAlternates[$path] = $altLinks['content'][$entry['id']] ?? [];
                $pageShareCards[$path] = self::cardForPage($entry['page'], $branding);
                $pageTargets[$path] = $primaryMeta[$entry['id']] ?? ['post', ''];
                if ($entry['json_ld'] !== null) {
                    $pageJsonLd[$path] = $entry['json_ld'];
                }
            }

            foreach ($localePages as $entry) {
                if ((++$rendered % 25) === 0) {
                    $tick();
                }
                $path = trim($prefix, '/') === ''
                    ? $this->outputPath($site->pagePathPattern, $entry['slug'])
                    : trim($prefix, '/') . '/' . $this->outputPath($site->pagePathPattern, $entry['slug']);
                if (isset($outputs[$path]) || isset($seenPaths[$path])) {
                    $this->logger->warning('Output path collision — content skipped', ['site' => $site->handle, 'path' => $path]);
                    continue;
                }
                $usedMedia += array_fill_keys($entry['media_ids'], true);
                $usedFiles += array_fill_keys($entry['file_ids'], true);
                $outputs[$path] = $this->injectRobotsMeta($this->renderPage($twig, 'page.html.twig', [
                    'page' => $entry['page'] + ['hreflang' => $altLinks['content'][$entry['id']] ?? []],
                    'content' => $entry['content'],
                    'json_ld' => $entry['json_ld'],
                ]), (string) $entry['robots']);
                $this->collectLcp($path, $entry, $locale === $site->primaryLocale, $pagePreloads, $lcpWarnPaths);
                $pageAlternates[$path] = $altLinks['content'][$entry['id']] ?? [];
                $pageShareCards[$path] = self::cardForPage($entry['page'], $branding);
                $pageTargets[$path] = $primaryMeta[$entry['id']] ?? ['page', ''];
                if ($entry['json_ld'] !== null) {
                    $pageJsonLd[$path] = $entry['json_ld'];
                }
            }

            foreach ($errorOut['outputs'] as $errorPath => $errorHtml) {
                $outputs[$errorPath] = $errorHtml;
                // Pseudo-type again, with the status as the slug — so
                // `exclude_slugs: 404` keeps a tag off the error page while
                // `target: all` still reaches it by default. Analytics on a 404
                // is how you find out which dead link people are following.
                $pageTargets[$errorPath] = ['error', basename($errorPath, '.html')];
                // An error row can carry a featured image like any other page;
                // with none, the site card applies through the fallback below.
                $pageShareCards[$errorPath] = $errorOut['cards'][$errorPath] ?? null;
            }
            $usedMedia += array_fill_keys($errorOut['media_ids'], true);
            $usedFiles += array_fill_keys($errorOut['file_ids'], true);
            unset($errorOut);

            // Per-locale sitemap.
            $sitemapName = $locale === $site->primaryLocale ? 'sitemap-' . $locale . '.xml' : 'sitemap-' . $locale . '.xml';
            $outputs[$sitemapName] = $this->localeSitemap($site, $prefix, $localePosts, $localePages, $homeEntry !== null && $homeEntry['noindex'], $archiveSitemap);

            // ——— Finalize, audit and WRITE this locale, then free it. The
            // build used to render every locale into one outputs array and run
            // these passes over the whole site at the end, which held every
            // rendered page of every locale in memory at once — on a large
            // multilingual site, that is what killed the worker. Every pass
            // touches one page at a time, and their ORDER is unchanged
            // (several append before </body>, so order IS the bytes), so
            // scoping them per locale changes nothing about the output. ———

            // What CSP do this locale's pages need? Scanned on the RAW
            // rendered pages, before any engine injection adds its own
            // scripts (those carry their own CSP handling below), so the
            // only <script> tags present came through the expansion chain —
            // all trusted, all data-fs-embed-marked, or the audit would FAIL
            // the build before _headers ever ships.
            foreach ($outputs as $path => $html) {
                if (!str_ends_with($path, '.html')) {
                    continue;
                }
                $demand = self::scriptDemand($html);
                $embedScriptLevel = max($embedScriptLevel, $demand);
                // EVERY html page gets an entry, zero included — the per-page
                // CSP below picks the site-wide default by majority, and it
                // cannot see a majority of zeroes it was never told about.
                $pageScriptMode[$path] = max($pageScriptMode[$path] ?? 0, $demand);
            }

            // Structured data: put each page's graph in its <head>. Passing it
            // to the template as `json_ld` only reaches shells that remember to
            // emit it — injecting here is what makes it true for EVERY site,
            // including a hand-written shell that never heard of the variable.
            // A shell that did emit it is detected and skipped, so nothing
            // doubles up.
            foreach ($pageJsonLd as $path => $graph) {
                if (isset($outputs[$path])) {
                    $outputs[$path] = self::injectJsonLd($outputs[$path], $graph);
                }
            }

            // LCP preload: passing `page.preload` to Twig only reaches shells
            // that emit it (both bundled shells do) — inject for the rest, so
            // LCP request discovery never hinges on one hand-written shell
            // line. Same reasoning as the JSON-LD injection above; a head that
            // already carries an image preload is left alone.
            foreach ($pagePreloads as $path => $link) {
                if (isset($outputs[$path])) {
                    $outputs[$path] = self::injectPreload($outputs[$path], $link);
                }
            }

            // Share cards: a page's own 1200x630 crop, else the site default.
            // Swept over EVERY emitted page rather than driven off the map
            // alone, for the same reason the development-mode sweep is — the
            // pages that go wrong are the ones with no content row behind
            // them, and a page nobody recorded a card for still deserves the
            // site's. Nothing is injected when neither exists.
            foreach ($outputs as $path => $html) {
                if (!str_ends_with($path, '.html')) {
                    continue;
                }
                $card = $pageShareCards[$path] ?? $siteCard;
                if ($card !== null) {
                    $outputs[$path] = self::injectShareCard($html, $card);
                }
            }

            // GTM/analytics CTA attributes (site toggle, default on): every
            // link and button on every page gets data-cta / data-cta-text /
            // data-cta-language / data-cta-location filled in (explicit
            // authored attributes always win). Runs BEFORE the user-script
            // injection so a pasted tag pack's own markup is never annotated;
            // the language switcher (injected later) carries its own
            // attributes from LangSwitcher::render.
            if ($site->ctaTracking) {
                foreach ($outputs as $path => $html) {
                    if (str_ends_with($path, '.html')) {
                        $outputs[$path] = CtaAnnotator::annotate($html, $this->pathLocale($site, $path), $locales);
                    }
                }
            }

            // User scripts (opt-in), now PER PAGE: each script lands only on the
            // pages its own rule targets, and each page's CSP is relaxed only
            // as far as what actually landed on it. A tag on one landing page
            // used to spend the zero-JS guarantee on every blog post on the
            // site — which is the guarantee the product is sold on.
            if ($hasScripts) {
                foreach ($outputs as $path => $html) {
                    if (!str_ends_with($path, '.html')) {
                        continue;
                    }
                    [$type, $slug] = $pageTargets[$path] ?? ['page', ''];
                    $blocks = $scriptsFor($type, $slug);
                    $joined = $blocks['head'] . $blocks['body_open'] . $blocks['body_end'];
                    if (trim($joined) === '') {
                        continue;
                    }
                    $outputs[$path] = $this->injectScripts($html, $blocks);
                    // What this page's own injected markup demands. Checked on
                    // the BLOCKS, not the finished page, so an inline-only tag
                    // never earns the `full` relax that `<script src>` needs.
                    $pageScriptMode[$path] = max($pageScriptMode[$path] ?? 0, self::scriptDemand($joined));
                }
            }

            // Render the language switcher where the shell placed a marker
            // (never auto-inserted). The page's own hreflang cluster is the
            // source of truth for which locale variants exist.
            if ($isMultilingual) {
                foreach ($outputs as $path => $html) {
                    // Every error page is skipped, not just the 404: a switcher
                    // there would offer locale variants of a destination nobody
                    // navigated to, and the page carries no hreflang cluster to
                    // build them from anyway.
                    if (str_ends_with($path, '.html') && !self::isErrorOutput($path)) {
                        $html = $this->injectLangSwitcher($html, $path, $pageAlternates[$path] ?? [], $site, $flagInfo['urls']);
                        // Remember the chosen language across navigation (cookie).
                        $outputs[$path] = $this->injectLangPersist($html, $path, $site);
                    }
                }
            }

            // The minifier deliberately preserves the <!--fs:languages-->
            // marker for the pass above — any marker nothing replaced
            // (single-language site, 404, or a page with fewer than 2 built
            // variants) must not ship.
            foreach ($outputs as $path => $html) {
                if (str_ends_with($path, '.html') && stripos($html, '<!--fs:languages-->') !== false) {
                    $outputs[$path] = str_ireplace('<!--fs:languages-->', '', $html);
                }
            }

            // Browser-language redirect on the primary home (#12): a tiny
            // inline script (data-fs-embed → audit-exempt) sends a first-time
            // visitor to their locale, primary as default. Only for
            // multilingual sites; only the primary locale's batch holds
            // index.html.
            if ($isMultilingual && isset($outputs['index.html'])) {
                $outputs['index.html'] = $this->injectLangRedirect($outputs['index.html'], $site);
            }

            // TOC scroll-spy (opt-in): a shell that renders a [data-fs-toc]
            // nav gets the highlighter injected on exactly those pages. Needs
            // the same inline-only CSP relax the language scripts use — the
            // flag survives the loop and decides _headers at the end.
            foreach ($outputs as $path => $html) {
                if (str_ends_with($path, '.html') && str_contains($html, 'data-fs-toc')) {
                    $outputs[$path] = $this->injectTocSpy($html);
                    $hasToc = true;
                }
            }

            // Development mode: the site is not finished, so NOTHING it
            // publishes may reach a search result. Done as a sweep over every
            // emitted page rather than per content row, because the pages most
            // likely to leak are the ones with no content row at all — the
            // blog index and 404 are rendered straight into the outputs and
            // never see the per-page robots flags. Every locale's batch gets
            // the same sweep, so it still covers every generated page and
            // every shell.
            if ($site->development) {
                foreach ($outputs as $path => $html) {
                    if (str_ends_with($path, '.html')) {
                        $outputs[$path] = self::injectSiteNoindex($html);
                    }
                }
            }
            $tick();

            // Audit BEFORE writing, so a locale with a pipeline-invariant
            // failure never reaches the tree. Locales already written stay
            // written — the price of not holding the whole site in memory —
            // but a failed build chains no publish, so nothing ships.
            foreach ($lcpWarnPaths as $lcpPath) {
                $audit->warnUnprioritizedLcp($lcpPath);
            }
            $audited = 0;
            foreach ($outputs as $path => $html) {
                $audit->auditPage($path, $html);
                if ((++$audited % 25) === 0) {
                    $tick();
                }
            }
            if ($audit->hasFailures()) {
                $this->reportAudit($site, $audit);
            }

            [$w, $s, $b] = $this->writeBatch($db, $outDir, $outputs, $force, $manifest, $seenPaths, $tick);
            $written += $w;
            $skipped += $s;
            $bytes += $b;
            $tick();
        }

        // Site-level outputs — their own final batch after every locale.
        $siteOutputs = [];
        // Sitemap index pointing at each locale's sitemap.
        $siteOutputs['sitemap.xml'] = $this->sitemapIndex($site, $locales);
        $siteOutputs['robots.txt'] = $this->robots($site);
        $siteOutputs['llms.txt'] = $this->llmsTxt($site, $primaryPostCards, $primaryPageCards);
        // CSP: full relax for user scripts OR a trusted embed that loads an
        // external/self script (a `.js` file embed, a compiled `.jsx` widget's
        // src, a verbatim component's tag loader); inline-only for the lang
        // scripts, the TOC scroll-spy, and inline-only trusted embeds.
        //
        // PER PAGE now. The baseline covers what the engine injects
        // site-wide — the language scripts on a multilingual site, the TOC
        // scroll-spy — and every page needing MORE than the baseline gets its
        // own `_headers` rule. So a GTM container on one landing page relaxes
        // that page and leaves `script-src 'none'` on all eighty blog posts,
        // which is the guarantee the product is sold on.
        //
        // `$pageScriptMode` already covers both sources exactly: trusted
        // embeds (scanned off the raw page by `scriptDemand`) and the blocks
        // each page's own targeted scripts actually injected.
        // A floor for what the engine injects AFTER the accounting above: the
        // language scripts on a multilingual site and the TOC scroll-spy both
        // need `inline`, and both land too late to be measured.
        $floor = ($isMultilingual || $hasToc) ? 1 : 0;
        $levels = array_map(static fn (int $l): int => max($floor, $l), $pageScriptMode);

        // The site-wide default is the MOST COMMON level, not the lowest — so
        // a genuinely site-wide tag raises `/*` and emits no per-page rules at
        // all, while a tag on one landing page leaves `/*` alone and costs one
        // exception. Getting this backwards is not just untidy: a site-wide
        // script would emit a rule per page and blow the rule cap on the
        // commonest configuration there is.
        $counts = array_count_values($levels);
        // Ascending, so a tie resolves to the LOWER level — the tightest
        // site-wide default the page count allows. Sorted rather than left in
        // array_count_values' insertion order, which follows page iteration
        // order and would make the emitted bytes depend on how many posts
        // happened to render first. Byte-identical rebuilds are a restore
        // guarantee here.
        ksort($counts);
        $baseLevel = $floor;
        $best = -1;
        foreach ($counts as $level => $n) {
            if ($n > $best) {
                $best = $n;
                $baseLevel = (int) $level;
            }
        }
        $baseLevel = max($baseLevel, $floor);

        $exceptions = [];
        foreach ($levels as $path => $level) {
            if ($level > $baseLevel) {
                $exceptions[$path] = $level;
            }
        }

        // Cloudflare Pages caps the number of `_headers` rules (documented at
        // 100), and a targeted page costs one or two rules PER LOCALE — three
        // pages on a nine-locale site is already ~27. Past the cap the right
        // answer is the honest one: relax site-wide and say so in the log. A
        // silently dropped rule would leave a page shipping a script its CSP
        // forbids, which is invisible except in a console nobody opens.
        $patterns = [];
        foreach ($exceptions as $path => $level) {
            foreach (self::headerPatterns($path) as $pattern) {
                $patterns[$pattern] = $level;
            }
        }
        if (\count($patterns) > self::MAX_HEADER_RULES) {
            $widest = max([$baseLevel, ...array_values($exceptions)]);
            $this->logger->warning(
                'Too many per-page CSP rules for _headers — relaxing site-wide instead',
                ['site' => $site->handle, 'rules' => \count($patterns), 'cap' => self::MAX_HEADER_RULES, 'mode' => self::scriptModeName($widest)],
            );
            $baseLevel = $widest;
            $patterns = [];
        }

        $siteOutputs['_headers'] = $this->headers(
            self::scriptModeName($baseLevel),
            $site->development,
            array_map(self::scriptModeName(...), $patterns),
        );
        // The ONE mode the whole site must fall back to where per-path rules
        // cannot reach: `.htaccess` and the preview/direct-serve default. The
        // widest any page needs, because a page whose script the CSP blocks is
        // broken, while a page with a looser policy than it needs is merely
        // not as tight as it could be.
        $scriptMode = self::scriptModeName(max([$baseLevel, ...array_values($pageScriptMode), 0]));
        /** @var list<array{from_path: string, to_path: string, status: int}> $redirectRows */
        $redirectRows = $db->fetchAllAssociative(
            'SELECT from_path, to_path, status FROM redirects ORDER BY from_path',
        );
        $redirects = $this->redirectsFile($site, [...array_keys($seenPaths), ...array_keys($siteOutputs)], $redirectRows);
        if ($redirects !== '') {
            $siteOutputs['_redirects'] = $redirects;
        }
        // A customer's own web host reads NEITHER of the two files above —
        // they are a Cloudflare Pages convention. Ship the Apache translation
        // so an ftp-published site keeps the same CSP, caching and canonical
        // 301 as every other mode. Only for ftp, which is why serve_mode is in
        // SiteService::outputSignature.
        if ($site->serveMode === 'ftp') {
            $siteOutputs['.htaccess'] = $this->htaccess->build(
                $site,
                $redirectRows,
                self::csp($scriptMode),
                array_map(intval(...), array_keys($errorPages)),
            );
        }

        // Opt-in: ship the WHOLE library, not just what pages embed. Media is
        // referenced-only by default (page weight, and the CDN file cap), but
        // that leaves an unembedded item with no public URL at all — and that
        // is exactly the item someone wants a link for. Republishing can never
        // fix it, because the build never copied the file. Unioned rather than
        // substituted, so referenced items are unaffected either way.
        if ($site->publishAllMedia) {
            $usedMedia += array_fill_keys($this->media->readyIdsOfKind($site->handle, ['image', 'video']), true);
            $usedFiles += array_fill_keys($this->media->readyIdsOfKind($site->handle, ['file']), true);
        }

        $tick();
        // Keyed as strings because $usedMedia (and so $mediaIds) mixes int and
        // string keys, exactly as the $keep set inside copyMedia already does.
        $shareCardIds = array_fill_keys(array_map('strval', array_keys($shareCards)), true);
        if ($site->socialImageMediaId !== null) {
            $shareCardIds[(string) $site->socialImageMediaId] = true;
        }
        $mediaFiles = $this->copyMedia($site, $sitePath, $outDir, array_keys($usedMedia), $tick, $shareCardIds);
        $this->copyFiles($site, $sitePath, $outDir, array_keys($usedFiles), $tick);
        $this->copyFlags($outDir, $flagInfo['copy']);

        foreach ($siteOutputs as $path => $content) {
            $audit->auditPage($path, $content);
        }
        // Logs every finding of the whole build (the per-locale checks above
        // only reported early on failure) and throws if anything failed.
        $this->reportAudit($site, $audit);

        [$w, $s, $b] = $this->writeBatch($db, $outDir, $siteOutputs, $force, $manifest, $seenPaths, $tick);
        $written += $w;
        $skipped += $s;
        $bytes += $b;

        // Whatever is left in $manifest was built before but not this time.
        $deleted = $this->deleteOrphans($db, $outDir, $manifest);
        Dirs::pruneEmpty($outDir);

        $this->databases->checkpoint($db);
        $tick();

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
            pages: \count($seenPaths),
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

    /**
     * How much CSP a page's shipped scripts need: 0 = none, 1 = inline
     * ('unsafe-inline'), 2 = external/self (the 'full' policy). Runs on the
     * rendered page BEFORE any engine injection, so every <script> present
     * came through the expansion chain — a verbatim component, a files-library
     * widget, verbatim content — all owner-trusted and data-fs-embed-marked
     * (an untrusted script never gets here: the sanitizer strips it on ingest
     * and PageAudit FAILs a non-exempt one before _headers ships). JSON-LD is
     * data, not executable script, and is skipped — a shell emitting it must
     * not relax anything.
     */
    private static function scriptDemand(string $html): int
    {
        if (stripos($html, '<script') === false) {
            return 0;
        }
        $level = 0;
        if (preg_match_all('/<script\b[^>]*>/i', $html, $m) > 0) {
            foreach ($m[0] as $tag) {
                if (preg_match('#\btype\s*=\s*["\']?application/ld\+json#i', $tag) === 1) {
                    continue;
                }
                if (preg_match('/\bsrc\s*=/i', $tag) === 1) {
                    return 2;
                }
                $level = 1;
            }
        }

        return $level;
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
        // Stored BEFORE the logger and BEFORE the throw below, for two
        // separate reasons. A WARN handed only to the logger lands in a file
        // inside the container and is read by nobody, which is how live sites
        // sat over the byte budget for months with the engine knowing. And a
        // build blocked by a FAIL should show EVERY failure on the Audit
        // screen, not just the one the exception happens to name.
        $this->auditLog->record($site->handle, $audit->findings());

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
        $twig = new Environment(new ShellTemplateLoader($shellPath . '/templates'), [
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
            'base_url' => $site->canonicalBaseUrl(),
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
     * Is this output path one of the engine's error pages?
     *
     * Matched on the FILENAME against the known status list rather than on
     * "looks like digits", so a legitimate page at `/2024.html` is not
     * mistaken for an error page and silently stripped of its language
     * switcher. Covers locale prefixes (`dk/404.html`) too.
     */
    private static function isErrorOutput(string $path): bool
    {
        $file = basename($path);
        foreach (Content::CODES as $code) {
            if ($file === $code . '.html') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every error page for one locale, keyed by output path.
     *
     * Deliberately NOT routed through `localizeList`, even though an error row
     * is an ordinary `page` row and the body needs the same expansion chain.
     * Three things differ, and each one matters:
     *
     * 1. NO CANONICAL. An error page is not a URL anyone should link to or
     *    index. `localizeList` would give it `canonical` = `/404.html`, and
     *    `JsonLdBuilder` hangs its whole `@graph` off the canonical `@id` — so
     *    a canonical here would mint a WebPage entity for the 404 and invite
     *    Google to treat it as real content.
     * 2. NO HREFLANG CLUSTER. Alternates advertise translations of a page
     *    worth reaching; a 404 is reached by accident.
     * 3. A MISSING TRANSLATION FALLS BACK TO THE PRIMARY instead of omitting
     *    the page. For ordinary content "missing = absent" is right — a
     *    half-translated site should not ship a Danish URL containing English.
     *    An error page is the opposite: `dk/404.html` must EXIST or a Danish
     *    visitor's miss has no page to serve at all, and an English 404 beats
     *    the customer's host serving its own.
     *
     * Template resolution is `{status}.html.twig` → `error.html.twig` →
     * `404.html.twig`, so a shell can style one code specially, handle all the
     * rest generically, or do neither and have every code reuse its 404.
     *
     * @param array<int, Content> $errorPages by status
     * @param array<int, array{url: string, width: int, height: int, alt: string}> $shareCards by media id
     * @param array<int, \Fastsite\Translation\Dto\ContentTranslation> $translations
     * @param list<\Fastsite\Component\Dto\ComponentHook> $hooks
     * @return array{outputs: array<string, string>, media_ids: list<int>, file_ids: list<int>, cards: array<string, array{url: string, width: int, height: int, alt: string}|null>}
     */
    private function renderErrorPages(
        Environment $twig,
        Site $site,
        array $errorPages,
        string $locale,
        array $translations,
        array $hooks,
        string $prefix,
        array $shareCards = [],
    ): array {
        $dir = trim($prefix, '/');
        $isPrimary = $locale === $site->primaryLocale;
        $outputs = [];
        $mediaIds = [];
        $fileIds = [];
        /** @var array<string, array{url: string, width: int, height: int, alt: string}|null> $cards */
        $cards = [];

        // A site with no 404 row keeps exactly what it had before this feature
        // existed: the shell's own 404 template with an engine-supplied title.
        // That is what makes the whole thing additive — the 17 sites already
        // live need no backfill, and "every site always has a 404" stays true.
        if (!isset($errorPages[404])) {
            // noindex here too, not only on the row-backed branch. The old
            // engine 404 shipped indexable (it got the directive only as a side
            // effect of development mode's blanket sweep), which is a soft-404
            // signal Google counts against the whole site. Leaving the fallback
            // indexable while a customised 404 is not would also be a
            // difference nobody could predict from the UI.
            $outputs[$dir === '' ? '404.html' : $dir . '/404.html'] = $this->injectRobotsMeta($this->renderPage($twig, '404.html.twig', [
                'page' => $this->simplePage($site->pageTitle('Not found'), '', [], $site->metaDescription),
                'content' => null,
                'json_ld' => null,
            ]), 'noindex');
        }

        foreach ($errorPages as $status => $item) {
            $translation = $isPrimary ? null : ($translations[$item->id] ?? null);

            $title = $translation?->title ?: $item->title;
            $body = $translation !== null && $translation->bodyHtml !== '' ? $translation->bodyHtml : $item->bodyHtml;
            $fragments = $translation !== null
                ? ['intro' => $translation->intro, 'tldr' => $translation->tldr, 'conclusion' => $translation->conclusion]
                : ['intro' => $item->intro, 'tldr' => $item->tldr, 'conclusion' => $item->conclusion];
            $description = $translation?->seoDescription ?: ($item->seoDescription ?: $item->excerpt);

            $path = $dir === '' ? $status . '.html' : $dir . '/' . $status . '.html';

            // Hooks match on the source row exactly as they do for ordinary
            // pages, so a `target: pages` or `all` CTA lands here too. That is
            // the intent — a 404 is one of the better places for a "find what
            // you were after" block — and `exclude_slugs` turns it off.
            $pageHooks = array_values(array_filter(
                $hooks,
                static fn (\Fastsite\Component\Dto\ComponentHook $h): bool => $h->appliesTo($item->type, $item->slug),
            ));
            if ($pageHooks !== []) {
                $body = HookInjector::inject($body, $pageHooks, $locale);
            }

            $card = ['title' => $title, 'excerpt' => '', 'slug' => $item->slug, 'url' => '/' . $status . '.html'];
            [$content, , $ids, $componentCss, $usedFileIds] = $this->renderContentFields(
                $site,
                $body,
                $item->featuredMediaId,
                $card,
                $locale,
                $fragments,
            );

            // An error row can carry a featured image like any other page, and
            // a 404 does get pasted into chats. No canonical is emitted here,
            // but a share card needs none — only an absolute image URL.
            $page = $this->simplePage($site->pageTitle($title), '', [], $description ?: $site->metaDescription)
                + $this->shareMeta($item->featuredMediaId !== null ? ($shareCards[$item->featuredMediaId] ?? null) : null)
                + ['component_css' => $componentCss, 'error_status' => $status];

            $template = $twig->getLoader()->exists($status . '.html.twig')
                ? $status . '.html.twig'
                : ($twig->getLoader()->exists('error.html.twig') ? 'error.html.twig' : '404.html.twig');

            // ALWAYS noindex, never conditional. An indexed error page is a
            // soft-404 signal against the whole site, and the content row is
            // forced noindex at save time too — this is the output half of the
            // same guarantee. `nofollow` is deliberately absent: the "back to
            // the front page" link is the one a stranded crawler should follow.
            $outputs[$path] = $this->injectRobotsMeta($this->renderPage($twig, $template, [
                'page' => $page,
                'content' => $content,
                'json_ld' => null,
            ]), 'noindex');

            $mediaIds = array_merge($mediaIds, $ids);
            $fileIds = array_merge($fileIds, $usedFileIds);
            // Its OWN card only — the site default is applied by the caller's
            // sweep, which also covers the shell-rendered 404 above.
            $cards[$path] = self::cardForPage($page, []);
        }

        return [
            'outputs' => $outputs,
            'media_ids' => array_values(array_unique($mediaIds)),
            'file_ids' => array_values(array_unique($fileIds)),
            'cards' => $cards,
        ];
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

        [$context, $preload, $ids, , ] = $this->renderContentFields($site, $content->bodyHtml, $content->featuredMediaId, $card, $site->primaryLocale, [
            'intro' => $content->intro,
            'tldr' => $content->tldr,
            'conclusion' => $content->conclusion,
        ]);

        return [$context, $preload, $ids];
    }

    /**
     * Runs one HTML fragment through the shared expansion chain: components →
     * static markup, forms → a real `<form>`, media `<img data-media-id>` →
     * `<picture>`, file links/embeds. Used for the body and for the structured
     * fragments (intro/tldr/conclusion) so an image, component or form
     * embedded in a fragment behaves exactly like one in the body.
     *
     * The LCP priority args are forwarded to the media expansion and only
     * passed for the MAIN body — an image in an intro/tldr/conclusion box is
     * never the page's LCP candidate.
     *
     * $pagePath is the built URL of the page being rendered; a form uses it
     * for the notification's "submitted from" line and for the return link.
     *
     * @return array{html: string, media_ids: list<int>, file_ids: list<int>, css: string, form_css: string, preload: string|null}
     */
    private function expandFragment(Site $site, string $html, string $locale, ?int $featuredMediaId = null, bool $autoPriority = false, string $pagePath = ''): array
    {
        // Components first — their output may itself contain media/file refs.
        $expanded = $this->componentRenderer->expand($site->handle, $html, $locale);
        foreach ($expanded['warnings'] as $warning) {
            $this->logger->warning('Component expansion', ['site' => $site->handle, 'message' => $warning]);
        }

        // Forms next, and BEFORE media/files: a form is generated markup that
        // never contains media or file references, but running it here keeps
        // the placeholder chain in one place and means a form is expanded by
        // the time PageAudit and CtaAnnotator see the page — the submit button
        // gets its CTA attributes for free.
        $forms = $this->formRenderer->expand($site, $expanded['html'], $locale, $pagePath);
        foreach ($forms['warnings'] as $warning) {
            $this->logger->warning('Form expansion', ['site' => $site->handle, 'message' => $warning]);
        }

        $body = $this->mediaRenderer->expandBody($site->handle, $forms['html'], $featuredMediaId, $autoPriority);

        // Files: <a data-file-id> → crawlable /files/ download links, then the
        // unified {{ filename }} embed (html/js inline & run, else link).
        $files = $this->fileRenderer->expandBody($site->handle, $body['html']);
        $embeds = $this->fileRenderer->expandEmbeds($site->handle, $files['html']);

        return [
            'html' => $embeds['html'],
            'media_ids' => $body['media_ids'],
            'file_ids' => array_values(array_unique(array_merge($files['file_ids'], $embeds['file_ids']))),
            'css' => $expanded['css'],
            // Kept separate from component CSS rather than concatenated: the
            // form stylesheet is byte-identical every time, so as its own entry
            // the caller's array_unique collapses it to one copy even when a
            // page has a form in both the body and a fragment.
            'form_css' => $forms['css'],
            'preload' => $body['preload'],
        ];
    }

    /**
     * Expands a body + its structured fragments into the template `content`
     * context: components → static markup, media `<img data-media-id>` →
     * `<picture>`, featured image + its LCP preload, heading ids + `toc`.
     * Returns any component CSS to inline in head. Fragment media/file ids
     * are merged into the returned lists so dist pruning keeps their assets.
     *
     * @param array<string, mixed> $card
     * @param array{intro?: string|null, tldr?: string|null, conclusion?: string|null} $fragments
     * @return array{0: array<string, mixed>, 1: string|null, 2: list<int>, 3: string, 4: list<int>}
     */
    private function renderContentFields(Site $site, string $bodyHtml, ?int $featuredMediaId, array $card, string $locale, array $fragments = []): array
    {
        $pagePath = (string) ($card['url'] ?? '');

        $main = $this->expandFragment($site, $bodyHtml, $locale, $featuredMediaId, true, $pagePath);
        $ids = $main['media_ids'];
        $fileIds = $main['file_ids'];
        $cssParts = array_values(array_filter([$main['css'], $main['form_css']], static fn (string $c): bool => $c !== ''));

        $fragmentHtml = ['intro' => '', 'tldr' => '', 'conclusion' => ''];
        foreach (array_keys($fragmentHtml) as $key) {
            $value = $fragments[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $frag = $this->expandFragment($site, $value, $locale, null, false, $pagePath);
            $fragmentHtml[$key] = $frag['html'];
            $ids = array_merge($ids, $frag['media_ids']);
            $fileIds = array_merge($fileIds, $frag['file_ids']);
            foreach ([$frag['css'], $frag['form_css']] as $part) {
                if ($part !== '') {
                    $cssParts[] = $part;
                }
            }
        }

        // The body expansion returns a preload when it prioritised an image in
        // place (the featured image authored in the body, or the auto-picked
        // hero); otherwise the featured_html render supplies it. Same image →
        // identical link either way, so there is never a competing pair.
        $preload = $main['preload'];
        $featuredHtml = '';

        if ($featuredMediaId !== null) {
            $featured = $this->mediaRenderer->featured($site->handle, $featuredMediaId);
            $featuredHtml = $featured['html'];
            $preload ??= $featured['preload'];
            if ($featuredHtml !== '') {
                $ids[] = $featuredMediaId;
            }
        }

        // YouTube links → responsive iframe embeds (#26). Stored content keeps a
        // plain link (sanitizer-safe); the build swaps it for the player, wrapped
        // in data-fs-embed so PageAudit exempts the external frame.
        $bodyOut = $this->expandYouTube($main['html']);

        // Heading anchors + table of contents: h2/h3 get slugified ids (hand-
        // written ids are respected) and the shell gets `content.toc` to render.
        $toc = TocBuilder::build($bodyOut);

        $context = $card + [
            'body_html' => $toc['html'],
            'featured_html' => $featuredHtml,
            'intro_html' => $fragmentHtml['intro'],
            'tldr_html' => $fragmentHtml['tldr'],
            'conclusion_html' => $fragmentHtml['conclusion'],
            'toc' => $toc['toc'],
        ];

        return [$context, $preload, array_values(array_unique($ids)), implode("\n", array_unique($cssParts)), array_values(array_unique($fileIds))];
    }

    /**
     * @return list<string>
     */
    private function buildLocales(Site $site): array
    {
        return array_values(array_unique([$site->primaryLocale, ...$site->locales]));
    }

    /** Which locale a built output path belongs to (mirrors localePrefix). */
    private function pathLocale(Site $site, string $path): string
    {
        foreach ($site->locales as $loc) {
            if (str_starts_with($path, $loc . '/')) {
                return $loc;
            }
        }

        return $site->primaryLocale;
    }

    private function localePrefix(Site $site, string $locale): string
    {
        return $locale === $site->primaryLocale ? '' : '/' . $locale;
    }

    /**
     * If a content page already claimed an engine-owned path (home/blog
     * index), evict it with a warning rather than let it be silently
     * overwritten (or overwrite the index). $seenPaths covers a page that
     * shipped in an EARLIER locale's batch — its bytes are already on disk,
     * but this locale's engine page overwrites the same path in its own
     * batch, so the engine page still wins exactly as it did when the whole
     * site was written at once.
     *
     * @param array<string, string> $outputs
     * @param array<string, true> $seenPaths
     */
    private function reserveOutput(array &$outputs, string $path, Site $site, array $seenPaths = []): void
    {
        if (isset($outputs[$path]) || isset($seenPaths[$path])) {
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
     * @param array<int, array{url: string, width: int, height: int, caption: string}> $featuredImages by media id
     * @param array{home?: string, blog?: string} $crumbLabels localized breadcrumb labels
     * @param list<\Fastsite\Component\Dto\ComponentHook> $hooks enabled component hooks (site-wide)
     * @param array<int, array{url: string, width: int, height: int, alt: string}> $shareCards by media id
     * @param array<int, \Fastsite\Category\Dto\Category> $categories by id
     * @return list<array<string, mixed>>
     */
    private function localizeList(
        array $items,
        string $locale,
        array $translations,
        Site $site,
        ?JsonLdBuilder $jsonLd = null,
        array $featuredImages = [],
        array $crumbLabels = [],
        array $hooks = [],
        array $shareCards = [],
        array $categories = [],
    ): array {
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
            $fragments = [
                'intro' => $isPrimary ? $item->intro : $translation->intro,
                'tldr' => $isPrimary ? $item->tldr : $translation->tldr,
                'conclusion' => $isPrimary ? $item->conclusion : $translation->conclusion,
            ];

            // A post's URL may carry its category (`/{category}/{slug}/`); the
            // segment is the same in every locale, only the slug translates.
            $path = $item->type === 'post'
                ? $prefix . \Fastsite\Shared\SiteLinks::applyPattern($site->postPathPattern, $slug, $item->categorySlug)
                : $prefix . str_replace('{slug}', $slug, $site->pagePathPattern);
            $category = $item->type === 'post' && $item->categoryId !== null ? ($categories[$item->categoryId] ?? null) : null;
            $categoryCard = $category === null ? null : [
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => \Fastsite\Shared\SiteLinks::categoryPath($site, $category->slug, 1, $locale),
            ];

            // Hooks match on the SOURCE row (type + primary slug), so every
            // locale's variant of a page gets the same injections; the body
            // they land in is this locale's.
            $pageHooks = array_values(array_filter(
                $hooks,
                static fn (\Fastsite\Component\Dto\ComponentHook $h): bool => $h->appliesTo($item->type, $item->slug),
            ));
            if ($pageHooks !== []) {
                $body = HookInjector::inject($body, $pageHooks, $locale);
            }

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
                'category' => $categoryCard,
            ];

            [$content, $preload, $ids, $componentCss, $fileIds] = $this->renderContentFields($site, $body, $item->featuredMediaId, $card, $locale, $fragments);

            $page = [
                'title' => $site->pageTitle($title),
                'seo_title' => $seoTitle !== '' ? $seoTitle : $site->pageTitle($title),
                'seo_description' => $seoDescription !== '' ? $seoDescription : $excerpt,
                'canonical' => $this->url($site, $path),
                'og_type' => $item->type === 'post' ? 'article' : 'website',
                'preload' => $preload,
                'component_css' => $componentCss,
            ];

            // A page own featured image IS its share card — otherwise every
            // link to this site looks identical wherever it is pasted. The
            // SOURCE row carries featuredMediaId, so every locale of a
            // translated page inherits the same card with nothing to resolve.
            // No featured image (and the blog index, which has no row at all)
            // falls through to the site default in the shell.
            $page += $this->shareMeta(
                $item->featuredMediaId !== null ? ($shareCards[$item->featuredMediaId] ?? null) : null,
            );

            $image = $item->featuredMediaId !== null ? ($featuredImages[$item->featuredMediaId] ?? null) : null;

            // The ingredients a caller needs to rebuild this item's graph under
            // a DIFFERENT url — home-as-content renders at the locale root, not
            // at the /home/ path this entry otherwise describes.
            $ld = [
                'title' => $title,
                'description' => (string) $page['seo_description'],
                'published' => $item->publishedAt,
                'modified' => $item->updatedAt,
                'author' => $item->authorName,
                'image' => $image,
                // Author-supplied JSON-LD is written in the SOURCE language and
                // is never translated, so it ships on the primary locale only.
                'stored' => $isPrimary ? $item->jsonLd : null,
            ];

            // Forside > Kategori > title for a post with a category — the trail
            // its URL describes. Without one, the blog index stands in, as it
            // always has.
            $crumbs = [['name' => (string) ($crumbLabels['home'] ?? 'Home'), 'url' => $this->url($site, $prefix . '/')]];
            if ($categoryCard !== null) {
                $crumbs[] = ['name' => $categoryCard['name'], 'url' => $this->url($site, $categoryCard['url'])];
            } elseif ($item->type === 'post') {
                $crumbs[] = ['name' => (string) ($crumbLabels['blog'] ?? 'Blog'), 'url' => $this->url($site, $prefix . '/blog/')];
            }
            $crumbs[] = ['name' => $title, 'url' => $this->url($site, $path)];
            // The same trail for the shell to render as visible breadcrumbs,
            // so what a reader sees and what the structured data says are one
            // list. Root-relative, like every link a shell writes.
            $page['breadcrumb'] = self::visibleCrumbs($site, $crumbs);

            $graph = $jsonLd?->graph(
                kind: $item->type === 'post' ? 'post' : 'page',
                url: $this->url($site, $path),
                title: $title,
                locale: $locale,
                description: (string) $ld['description'],
                published: $item->publishedAt,
                modified: $item->updatedAt,
                author: $item->authorName,
                image: $image,
                breadcrumb: $crumbs,
                stored: $ld['stored'],
            );

            $entries[] = [
                'id' => $item->id,
                'category_id' => $category?->id,
                'slug' => $slug,
                'noindex' => $item->noindex,
                // Meta robots directives this page must carry ('' = none).
                'robots' => implode(', ', array_filter([$item->noindex ? 'noindex' : null, $item->nofollow ? 'nofollow' : null])),
                'card' => $card,
                'page' => $page,
                'content' => $content,
                'json_ld' => $graph,
                'ld' => $ld,
                'media_ids' => $ids,
                'file_ids' => $fileIds,
            ];
        }

        return $entries;
    }

    /**
     * The JSON-LD trail as the shell's `page.breadcrumb`: root-relative links,
     * and NO link on the last crumb (it is the page you are on — the same
     * rule Google applies to the structured data).
     *
     * @param list<array{name: string, url: string}> $crumbs absolute
     * @return list<array{name: string, url: string|null}>
     */
    private static function visibleCrumbs(Site $site, array $crumbs): array
    {
        $base = $site->canonicalBaseUrl();
        $out = [];
        $last = \count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            $url = $crumb['url'];
            if ($base !== '' && str_starts_with($url, $base)) {
                $url = substr($url, \strlen($base)) ?: '/';
            }
            $out[] = ['name' => $crumb['name'], 'url' => $i === $last ? null : $url];
        }

        return $out;
    }

    /**
     * Every archive page of every category that has posts in this locale.
     *
     * Page 1 lives at the category pattern, page N at `…/page/N/` (the
     * WordPress shape, so a ported site keeps those URLs). A category with no
     * published post in this locale gets NO archive: an empty listing is a
     * thin page, and on a secondary locale it would be a page in a language
     * nothing on it is written in.
     *
     * Template: `category.html.twig`, else the shell's `blog-index.html.twig`
     * with the same context — so a shell written before categories existed
     * still builds every archive URL (it just titles them the way its blog
     * index does, which the build log says).
     *
     * @param list<array<string, mixed>> $localePosts
     * @param array<int, \Fastsite\Category\Dto\Category> $categories
     * @param array{home?: string, blog?: string, page?: string} $crumbLabels
     * @param array<int, list<array{hreflang: string, href: string}>> $clusters by category id (page 1)
     * @return array{outputs: array<string, string>, targets: array<string, array{0: string, 1: string}>, alternates: array<string, list<array{hreflang: string, href: string}>>, json_ld: array<string, string>, sitemap: list<string>}
     */
    private function renderArchives(
        Environment $twig,
        Site $site,
        string $locale,
        array $localePosts,
        array $categories,
        array $crumbLabels,
        ?JsonLdBuilder $jsonLd,
        array $clusters,
    ): array {
        $out = ['outputs' => [], 'targets' => [], 'alternates' => [], 'json_ld' => [], 'sitemap' => []];
        if ($categories === []) {
            return $out;
        }

        /** @var array<int, list<array<string, mixed>>> $byCategory */
        $byCategory = [];
        foreach ($localePosts as $entry) {
            if ($entry['category_id'] !== null && isset($categories[$entry['category_id']])) {
                $byCategory[(int) $entry['category_id']][] = $entry;
            }
        }
        if ($byCategory === []) {
            return $out;
        }

        $template = $twig->getLoader()->exists('category.html.twig') ? 'category.html.twig' : 'blog-index.html.twig';
        if ($template !== 'category.html.twig' && $locale === $site->primaryLocale) {
            $this->logger->info('Shell has no category.html.twig — category archives render through blog-index.html.twig', ['site' => $site->handle]);
        }

        $prefix = $this->localePrefix($site, $locale);
        $homeUrl = $this->url($site, $prefix . '/');
        $perPage = max(1, $site->archivePerPage);

        foreach ($byCategory as $id => $entries) {
            $category = $categories[$id];
            $pages = array_chunk($entries, $perPage);
            $total = \count($pages);
            $pageUrls = [];
            for ($n = 1; $n <= $total; ++$n) {
                $pageUrls[$n] = \Fastsite\Shared\SiteLinks::categoryPath($site, $category->slug, $n, $locale);
            }
            $description = $category->seoDescription !== ''
                ? $category->seoDescription
                : (trim((string) preg_replace('/\s+/', ' ', strip_tags($category->descriptionHtml))) ?: $site->metaDescription);
            $categoryCtx = [
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => $pageUrls[1],
                'description_html' => $category->descriptionHtml,
                'post_count' => \count($entries),
            ];

            foreach ($pages as $i => $chunk) {
                $n = $i + 1;
                $url = $pageUrls[$n];
                $path = trim($url, '/') . '/index.html';
                $name = $n === 1 ? $category->name : $category->name . ' (' . ($crumbLabels['page'] ?? 'Page') . ' ' . $n . ')';
                $title = $n === 1 && $category->seoTitle !== '' ? $category->seoTitle : $site->pageTitle($name);

                $crumbs = [
                    ['name' => (string) ($crumbLabels['home'] ?? 'Home'), 'url' => $homeUrl],
                    ['name' => $category->name, 'url' => $this->url($site, $pageUrls[1])],
                ];
                if ($n > 1) {
                    $crumbs[] = ['name' => $name, 'url' => $this->url($site, $url)];
                }

                $items = [];
                foreach ($chunk as $entry) {
                    if (!$entry['noindex']) {
                        $items[] = ['name' => (string) $entry['card']['title'], 'url' => $this->url($site, (string) $entry['card']['url'])];
                    }
                }
                $graph = $jsonLd?->graph(
                    kind: 'blog',
                    url: $this->url($site, $url),
                    title: $title,
                    locale: $locale,
                    description: $description,
                    breadcrumb: $crumbs,
                    items: $items,
                );

                // Each page canonicals to ITSELF. Pointing page 2 at page 1 is
                // the classic mistake: it tells Google the posts listed only on
                // page 2 are not worth discovering from here.
                $hreflang = $n === 1 ? ($clusters[$id] ?? []) : [];
                $page = $this->simplePage($title, $this->url($site, $url), $hreflang, $description)
                    + ['breadcrumb' => self::visibleCrumbs($site, $crumbs)];

                $pageLinks = [];
                foreach ($pageUrls as $pn => $pu) {
                    $pageLinks[] = ['number' => $pn, 'url' => $pu, 'current' => $pn === $n];
                }

                $out['outputs'][$path] = $this->renderPage($twig, $template, [
                    'page' => $page,
                    'posts' => array_map(static fn (array $e): array => $e['card'], $chunk),
                    'category' => $categoryCtx,
                    'pagination' => [
                        'current' => $n,
                        'total' => $total,
                        'prev_url' => $n > 1 ? $pageUrls[$n - 1] : null,
                        'next_url' => $n < $total ? $pageUrls[$n + 1] : null,
                        'pages' => $pageLinks,
                    ],
                    'json_ld' => $graph,
                ]);
                $out['targets'][$path] = ['category', $category->slug];
                $out['alternates'][$path] = $hreflang;
                if ($graph !== null) {
                    $out['json_ld'][$path] = $graph;
                }
                // Page 1 only. Later pages are reachable from it, and a
                // sitemap entry per page of every archive is noise Google
                // ignores anyway.
                if ($n === 1) {
                    $out['sitemap'][] = $url;
                }
            }
        }

        return $out;
    }

    /**
     * Precomputes hreflang clusters: for each content item, and for the
     * home/blog index, the list of {hreflang, href} alternates over the
     * locales where it actually renders, plus x-default → primary.
     *
     * Takes slugs only (not full translation rows) on purpose: the clusters
     * span every locale, and this is the one read the build needs up front —
     * full rows load per locale inside the build loop.
     *
     * @param list<string> $locales
     * @param list<Content> $posts
     * @param list<Content> $pages
     * @param array<string, array<int, string>> $slugsByLocale translated slug per content id, per locale
     * @param array<int, \Fastsite\Category\Dto\Category> $categories
     * @return array{content: array<int, list<array{hreflang: string, href: string}>>, home: list<array{hreflang: string, href: string}>, blog: list<array{hreflang: string, href: string}>, category: array<int, list<array{hreflang: string, href: string}>>}
     */
    private function alternateLinks(Site $site, array $locales, array $posts, array $pages, array $slugsByLocale, array $categories = []): array
    {
        if (\count($locales) < 2) {
            return ['content' => [], 'home' => [], 'blog' => [], 'category' => []];
        }

        // A category's archive exists in a locale exactly when at least one of
        // its posts renders there — the same rule renderArchives applies.
        /** @var array<int, array<string, true>> $archiveLocales */
        $archiveLocales = [];
        foreach ($posts as $post) {
            if ($post->categoryId === null || !isset($categories[$post->categoryId])) {
                continue;
            }
            foreach ($locales as $locale) {
                if ($locale === $site->primaryLocale || isset($slugsByLocale[$locale][$post->id])) {
                    $archiveLocales[$post->categoryId][$locale] = true;
                }
            }
        }
        $categoryLinks = [];
        foreach ($archiveLocales as $id => $present) {
            if (\count($present) < 2) {
                continue;
            }
            $links = [];
            foreach ($locales as $locale) {
                if (isset($present[$locale])) {
                    $links[] = ['hreflang' => $locale, 'href' => $this->url($site, \Fastsite\Shared\SiteLinks::categoryPath($site, $categories[$id]->slug, 1, $locale))];
                }
            }
            $links[] = ['hreflang' => 'x-default', 'href' => $this->url($site, \Fastsite\Shared\SiteLinks::categoryPath($site, $categories[$id]->slug))];
            $categoryLinks[$id] = $links;
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
                    $slug = $slugsByLocale[$locale][$id] ?? null;
                    if ($slug === null) {
                        continue;
                    }
                }
                $href = $this->url($site, $prefix . $this->itemPath($site, $item, $slug));
                $links[] = ['hreflang' => $locale, 'href' => $href];
            }
            if (\count($links) > 1) {
                $links[] = ['hreflang' => 'x-default', 'href' => $this->url($site, $this->itemPath($site, $item, $item->slug))];
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

        return ['content' => $content, 'home' => $home, 'blog' => $blog, 'category' => $categoryLinks];
    }

    /**
     * A content item's path WITHOUT a locale prefix, for one slug. Posts go
     * through the post pattern with the item's category; pages through the
     * page pattern. Deliberately not SiteLinks::pathOf — that sends a page
     * slugged `home` to `/`, while the clusters here have always used the
     * slug path for it and anchor the home page separately.
     */
    private function itemPath(Site $site, Content $item, string $slug): string
    {
        return $item->type === 'post'
            ? \Fastsite\Shared\SiteLinks::applyPattern($site->postPathPattern, $slug, $item->categorySlug)
            : str_replace('{slug}', $slug, $site->pagePathPattern);
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
     * @param \Closure(): void|null $tick heartbeat — a media-heavy site copies
     *   thousands of variant files, silent stretches get the job reclaimed
     * @param array<array-key, true> $shareCardIds ids whose 1200x630 og crop
     *   some page references as og:image — those crops must ship. Built from
     *   strval'd ids, but PHP silently re-keys a numeric string to an int, so
     *   the key type is array-key and the lookup below casts to string for the
     *   same reason: `isset($a['7'])` finds int key 7, and vice versa.
     */
    private function copyMedia(Site $site, string $sitePath, string $outDir, array $mediaIds, ?\Closure $tick = null, array $shareCardIds = []): int
    {
        $tick ??= static function (): void {};
        $keep = array_fill_keys(array_map('strval', $mediaIds), true);

        $copied = 0;
        $processed = 0;
        foreach ($mediaIds as $id) {
            if ((++$processed % 25) === 0) {
                $tick();
            }
            $sourceDir = $sitePath . '/media/variants/' . $id;
            $targetDir = $outDir . '/media/' . $id;
            if (!is_dir($sourceDir)) {
                // No source variants: anything previously shipped for this id
                // is superseded and must go, or a replace whose variants dir
                // vanished would keep serving the old files forever (the
                // directory-level prune below only fires for ids that left the
                // build entirely).
                foreach (glob($targetDir . '/*') ?: [] as $shipped) {
                    @unlink($shipped);
                }
                continue;
            }
            if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create media output dir: ' . $targetDir);
            }
            // What this id should hold after the copy. A replaced item's old
            // revision is still on disk under its previous filename, and the
            // directory prune below only fires for ids that left the build
            // entirely — so without this the superseded variants would ship
            // (and keep being served) forever.
            $expected = [];
            foreach (glob($sourceDir . '/*') ?: [] as $file) {
                if (str_ends_with($file, '-og.jpg') && !isset($shareCardIds[(string) $id])) {
                    // OG crops are dist bloat — except the ones a page actually
                    // hands to a scraper: the site default card and every
                    // featured image used as a page card. Those are referenced
                    // by absolute self-URL, so they must ship or the scraper
                    // gets a 404. Drop a previously shipped crop so an
                    // incremental build stays byte-identical to a fresh one —
                    // which is also what un-ships a crop when a page stops
                    // being the reason it was there.
                    $stale = $targetDir . '/' . basename($file);
                    if (is_file($stale)) {
                        @unlink($stale);
                    }
                    continue;
                }
                $target = $targetDir . '/' . basename($file);
                $expected[basename($file)] = true;
                // Variant filenames are immutable per (media id, revision), so
                // existence (plus a size sanity check for a truncated prior
                // copy) is a correct skip — no need to hash both files every
                // build. A REPLACED item lands under a new filename, which is
                // exactly what defeats the year-long immutable cache.
                if (!is_file($target) || filesize($file) !== filesize($target)) {
                    copy($file, $target);
                }
                ++$copied;
            }

            foreach (glob($targetDir . '/*') ?: [] as $shipped) {
                if (!isset($expected[basename($shipped)])) {
                    @unlink($shipped);
                }
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
     * @param \Closure(): void|null $tick heartbeat, same reasoning as copyMedia
     */
    private function copyFiles(Site $site, string $sitePath, string $outDir, array $fileIds, ?\Closure $tick = null): void
    {
        $tick ??= static function (): void {};
        $keep = array_fill_keys(array_map('strval', $fileIds), true);

        $processed = 0;
        foreach ($this->fileRenderer->filesForCopy($site->handle, $fileIds) as $id => $meta) {
            if ((++$processed % 25) === 0) {
                $tick();
            }
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
            // A replaced file ships under its revisioned name; the superseded
            // one would otherwise stay in dist and keep being served.
            foreach (glob($targetDir . '/*') ?: [] as $shipped) {
                if ($shipped !== $target && basename($shipped) !== basename($target)) {
                    @unlink($shipped);
                }
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
        // Preview is always someone looking, so it always re-renders.
        $this->build($site, null, force: true);
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
                // Same origin the pages were rendered with, or absolute links
                // to a www-canonical site would survive relativization.
                file_put_contents($target, PreviewRelativizer::relativize((string) file_get_contents($file->getPathname()), $rel, $site->canonicalBaseUrl()));
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
            'title' => $site->pageTitle($content->title),
            'seo_title' => $content->seoTitle !== '' ? $content->seoTitle : $site->pageTitle($content->title),
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
        return \Fastsite\Shared\SiteLinks::pathOf($site, $content);
    }

    private function outputPath(string $pattern, string $slug): string
    {
        return trim(str_replace('{slug}', $slug, $pattern), '/') . '/index.html';
    }

    /**
     * Every absolute URL the build emits — canonical, OG, hreflang, sitemaps,
     * llms.txt, robots — goes through here, on the site's CANONICAL origin
     * (apex by default, www. when the operator picked it). That is what keeps
     * the host-level 301 and the canonical tag pointing the same way.
     */
    private function url(Site $site, string $path): string
    {
        $base = $site->canonicalBaseUrl();

        return $base !== '' ? $base . $path : $path;
    }

    /**
     * The host every canonical this build emits will carry — read off the same
     * origin url() uses, so the audit can never disagree with the renderer.
     */
    private static function canonicalHostOf(Site $site): string
    {
        $base = $site->canonicalBaseUrl();

        return $base === '' ? '' : (string) (parse_url($base, PHP_URL_HOST) ?: '');
    }

    /**
     * The site's structured-data builder, or null when the site has it off.
     * Built ONCE per build so every page — every locale — resolves to the same
     * organization, website and logo entity by @id.
     *
     * @param array<string, mixed> $branding from {@see resolveBranding}
     */
    private function jsonLdBuilder(Site $site, array $branding): ?JsonLdBuilder
    {
        if (!$site->structuredData) {
            return null;
        }

        $logo = null;
        if (isset($branding['logo_header_url'])) {
            $logo = [
                // Absolute: a crawler resolving the graph is not necessarily
                // reading it in the context of the page it came from.
                'url' => $this->url($site, (string) $branding['logo_header_url']),
                'width' => (int) ($branding['logo_header_width'] ?? 0),
                'height' => (int) ($branding['logo_header_height'] ?? 0),
                'caption' => (string) ($branding['logo_header_alt'] ?? ''),
            ];
        }

        return new JsonLdBuilder(
            origin: rtrim($site->canonicalBaseUrl(), '/'),
            siteName: $site->name,
            entityType: $site->schemaType,
            sameAs: $site->sameAs,
            siteDescription: $site->metaDescription,
            logo: $logo,
        );
    }

    /**
     * Everything the build needs from every content item featured image,
     * resolved in ONE query for the whole build — per-item loading would cost
     * a round trip per content item PER LOCALE.
     *
     * Two maps, both keyed by media id, because the two consumers want
     * different variants of the same images: `ld` is the JSON-LD image node
     * (the widest responsive jpg/png, what an `<img src>` resolves to) and
     * `share` is the social card (the 1200x630 og crop first). One load, one
     * pass, no second trip.
     *
     * @param list<Content> $items
     * @return array{
     *     ld: array<int, array{url: string, width: int, height: int, caption: string}>,
     *     share: array<int, array{url: string, width: int, height: int, alt: string}>,
     * }
     */
    private function featuredImageMaps(Site $site, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->featuredMediaId !== null) {
                $ids[$item->featuredMediaId] = true;
            }
        }
        if ($ids === []) {
            return ['ld' => [], 'share' => []];
        }

        $out = [];
        $share = [];
        foreach ($this->media->loadManyWithVariants($site->handle, array_keys($ids)) as $id => $media) {
            if ($media->status !== 'ready') {
                continue;
            }
            // The page's own share card. Resolved in the SAME pass as the
            // JSON-LD node because both want every featured image with its
            // variants, and one batched load is the whole point of this method.
            if (($card = $this->shareCard($site, (int) $id, $media)) !== null) {
                $share[(int) $id] = $card;
            }
            // The widest jpg/png — the same fallback variant <img src> points
            // at, and the one every crawler can decode without AVIF/WebP support.
            $best = null;
            foreach ($media->variants as $variant) {
                if ($variant->purpose !== 'responsive' || !\in_array($variant->format, ['jpg', 'png'], true)) {
                    continue;
                }
                if ($best === null || $variant->width > $best->width) {
                    $best = $variant;
                }
            }
            if ($best === null) {
                continue;
            }
            $out[(int) $id] = [
                'url' => $this->url($site, '/media/' . $id . '/' . basename($best->diskPath)),
                'width' => $best->width,
                'height' => $best->height,
                'caption' => $media->alt,
            ];
        }

        return ['ld' => $out, 'share' => $share];
    }

    /**
     * The variant a social scraper should be handed for one image: the
     * 1200x630 `og` crop the media pipeline renders for EVERY image, else the
     * widest responsive JPEG/PNG. Absolute, because scrapers do not resolve
     * root-relative paths.
     *
     * ONE selection for the site default card and for a page own card, so the
     * two cannot drift. The format restriction on the fallback is the favicon
     * lesson again: Facebook, LinkedIn, X and Slack decode jpg and png, avif
     * is not supported and webp is patchy — and the site card fallback used to
     * take the widest responsive variant of ANY format, so a share image with
     * no og crop could hand every scraper an avif. That renders perfectly
     * everywhere a human looks; the only symptom is a blank card in a stranger
     * chat window months later, with nothing pointing at the cause. Nothing
     * loses its card by tightening this: the pipeline always emits a jpg (or a
     * png, for real transparency) at the largest width.
     *
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    private function shareCard(Site $site, int $id, \Fastsite\Media\Dto\Media $media): ?array
    {
        $chosen = null;
        foreach ($media->variants as $variant) {
            if ($variant->purpose === 'og') {
                $chosen = $variant;
                break;
            }
            if ($variant->purpose === 'responsive'
                && \in_array($variant->format, ['jpg', 'png'], true)
                && ($chosen === null || $variant->width > $chosen->width)
            ) {
                $chosen = $variant;
            }
        }
        if ($chosen === null) {
            return null;
        }

        return [
            'url' => $this->url($site, '/media/' . $id . '/' . basename($chosen->diskPath)),
            'width' => $chosen->width,
            'height' => $chosen->height,
            'alt' => $media->alt,
        ];
    }

    /**
     * A share card as the `page.og_image_*` keys a shell reads, or nothing at
     * all. Merged with `+` at every call site, so it can never overwrite a key
     * the page already owns.
     *
     * @param array{url: string, width: int, height: int, alt: string}|null $card
     * @return array<string, mixed>
     */
    private function shareMeta(?array $card): array
    {
        return $card === null ? [] : [
            'og_image_url' => $card['url'],
            'og_image_width' => $card['width'],
            'og_image_height' => $card['height'],
            'og_image_alt' => $card['alt'],
        ];
    }

    /**
     * The share card one rendered page should carry: its own, else the site's
     * default. Read off the page context the template was handed, so an
     * injected tag can never disagree with what a shell that DOES read
     * `page.og_image_*` already emitted. Pass `[]` as the branding to ask for
     * a page's OWN card only.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $branding
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    private static function cardForPage(array $page, array $branding): ?array
    {
        foreach ([['og_image_', $page], ['social_image_', $branding]] as [$prefix, $src]) {
            $url = $src[$prefix . 'url'] ?? null;
            if (\is_string($url) && $url !== '') {
                return [
                    'url' => $url,
                    'width' => (int) ($src[$prefix . 'width'] ?? 0),
                    'height' => (int) ($src[$prefix . 'height'] ?? 0),
                    'alt' => (string) ($src[$prefix . 'alt'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * Writes a page's social share card into the rendered <head>: `og:image`
     * with its width/height/alt, plus `twitter:image`.
     *
     * Leaving those tags to the SHELL made the per-page card a promise only a
     * maintained template kept, and measured across the live fleet 11 of the
     * 12 sampleable sites were wrong: five emitted no `og:image` AT ALL (both
     * bundled shells wrap the block in `{% if share %}`, so a site with no
     * default card shipped nothing), six repeated one picture on every page,
     * and one emitted a per-page image that was the wrong FILE — the 2048px
     * responsive jpg instead of the crop. Injecting here is the same move that
     * makes structured data, the robots flags and the LCP preload true for
     * every site regardless of shell.
     *
     * An existing card in the head is REPLACED rather than left alone.
     * Skipping is the safer-sounding rule and it is the wrong one: the shell
     * that confidently names a 2048px jpg is precisely the case that needs
     * fixing, and two competing answers are worse than one authoritative one —
     * ours is the one with a real 1200x630 file in dist behind it. `og:image`
     * can only come from a template; no content author can set it, so nothing
     * anybody deliberately wrote is being overwritten. The whole
     * `og:image*`/`twitter:image*` family goes, because a stale
     * `og:image:secure_url` or `:type` left beside a replaced `og:image` is
     * what a scraper reads first.
     *
     * Scoped to the head, like injectJsonLd: a trusted verbatim body embed may
     * carry meta tags of its own and they are none of our business.
     *
     * @param array{url: string, width: int, height: int, alt: string} $card
     */
    private static function injectShareCard(string $html, array $card): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html; // no head to inject into — never fail a build over it
        }

        $head = (string) preg_replace(
            '#<meta\b[^>]*\b(?:property|name)\s*=\s*["\']?(?:og|twitter):image\b[^>]*>#i',
            '',
            substr($html, 0, $headEnd),
        );

        $tags = '<meta property="og:image" content="' . htmlspecialchars($card['url'], ENT_QUOTES) . '">';
        if ($card['width'] > 0 && $card['height'] > 0) {
            $tags .= '<meta property="og:image:width" content="' . $card['width'] . '">'
                . '<meta property="og:image:height" content="' . $card['height'] . '">';
        }
        // An empty alt attribute is worse than none: it tells a reader the
        // picture is decorative, and a share card never is.
        if ($card['alt'] !== '') {
            $tags .= '<meta property="og:image:alt" content="' . htmlspecialchars($card['alt'], ENT_QUOTES) . '">';
        }
        $tags .= '<meta name="twitter:image" content="' . htmlspecialchars($card['url'], ENT_QUOTES) . '">';

        // `summary` was the shell's answer to having no picture; with one, the
        // large card is the entire point. Rewritten when a card meta is there
        // to rewrite, and emitted when the shell wrote none at all — X shows a
        // small card by default, which is the same wasted picture by another
        // route.
        if (preg_match('#<meta\b[^>]*\b(?:name|property)\s*=\s*["\']?twitter:card\b[^>]*>#i', $head, $m) === 1) {
            $head = str_replace(
                $m[0],
                (string) preg_replace('#content\s*=\s*(["\'])\s*summary\s*\1#i', 'content="summary_large_image"', $m[0]),
                $head,
            );
        } else {
            $tags .= '<meta name="twitter:card" content="summary_large_image">';
        }

        return $head . $tags . substr($html, $headEnd);
    }

    /**
     * Inject a page's JSON-LD graph immediately before </head>.
     *
     * Passing the graph to the template as `json_ld` only reaches shells that
     * remember to emit it — a custom shell missing that one line ships no
     * structured data at all and nothing warns anyone. Injecting here is what
     * makes the guarantee site-wide. A shell that DID emit it is detected and
     * left alone, so nothing is ever emitted twice.
     *
     * The already-present check is scoped to the HEAD on purpose: a trusted
     * verbatim embed in the BODY may carry its own ld+json block, and that must
     * not suppress the engine's.
     */
    private static function injectJsonLd(string $html, string $graph): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html; // no head to inject into — never fail a build over it
        }
        if (stripos(substr($html, 0, $headEnd), 'application/ld+json') !== false) {
            return $html;
        }

        return substr($html, 0, $headEnd)
            . '<script type="application/ld+json">' . $graph . '</script>'
            . substr($html, $headEnd);
    }

    /**
     * Records a rendered content page's LCP facts: the preload to guarantee in
     * its head, and — on the primary locale only, since the fix is one field
     * on the source row and nine locale-prefixed repeats of the same finding
     * help nobody — the WARN for a page whose body renders images while none
     * ended up prioritised (all images below hero size and no
     * featured_media_id, or a featured image deleted after being set). That
     * page almost certainly ships a lazy LCP, which is exactly the silent
     * 99-performance failure this exists to surface at build time.
     *
     * @param array<string, mixed> $entry a localizeList render entry
     * @param array<string, string> $pagePreloads appended to in place
     * @param list<string> $lcpWarnPaths appended to in place
     */
    private function collectLcp(string $path, array $entry, bool $isPrimary, array &$pagePreloads, array &$lcpWarnPaths): void
    {
        $preload = $entry['page']['preload'] ?? null;
        if (\is_string($preload) && $preload !== '') {
            $pagePreloads[$path] = $preload;
        } elseif ($isPrimary && ($entry['media_ids'] ?? []) !== []) {
            $lcpWarnPaths[] = $path;
        }
    }

    /**
     * Puts the page's LCP <link rel=preload> into the rendered <head> when the
     * shell did not emit `page.preload` itself — like injectJsonLd, injecting
     * is what makes the guarantee hold for a hand-written shell that never
     * heard of the variable. Any existing image preload in the head (ours via
     * the template, or a shell's own hand-rolled one) means skip: two
     * high-priority image preloads compete instead of helping.
     */
    private static function injectPreload(string $html, string $link): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html; // no head to inject into — never fail a build over it
        }
        $head = substr($html, 0, $headEnd);
        if (preg_match('/<link\b(?=[^>]*\brel="preload")(?=[^>]*\bas="image")[^>]*>/i', $head) === 1) {
            return $html;
        }

        return $head . $link . substr($html, $headEnd);
    }

    /**
     * Per-locale sitemap listing that locale's rendered URLs.
     *
     * @param list<array<string, mixed>> $localePosts
     * @param list<array<string, mixed>> $localePages
     * @param list<string> $archivePaths page 1 of each category archive
     */
    private function localeSitemap(Site $site, string $prefix, array $localePosts, array $localePages, bool $homeNoindex = false, array $archivePaths = []): string
    {
        // Only indexable URLs belong in a sitemap — a noindex'd page in it
        // sends crawlers contradictory signals (Search Console flags it).
        $entries = $homeNoindex ? [] : [['loc' => $this->url($site, $prefix . '/'), 'lastmod' => null]];
        $entries[] = ['loc' => $this->url($site, $prefix . '/blog/'), 'lastmod' => null];
        foreach ($archivePaths as $archivePath) {
            $entries[] = ['loc' => $this->url($site, (string) $archivePath), 'lastmod' => null];
        }
        foreach ([...$localePosts, ...$localePages] as $entry) {
            if (!empty($entry['noindex'])) {
                continue;
            }
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
        $base = $site->canonicalBaseUrl();
        $out = $this->robotsBanner($site) . "\n\n";

        // Development mode shuts the door completely: no crawling, no sitemap
        // advertising, and the noindex/nofollow still on every page underneath.
        //
        // Know what the two mechanisms actually do, because they are mutually
        // exclusive rather than additive. `Disallow: /` stops the FETCH, which
        // is what keeps bots, scrapers and AI crawlers off a site that is not
        // ready — and on a site that was never indexed (no inbound links, so
        // nothing to discover) it is completely sufficient on its own. But a
        // crawler that cannot fetch also cannot READ the noindex, so on a site
        // Google has ALREADY indexed this freezes the existing entries in place
        // instead of removing them. Pulling a live site out of the index needs
        // the opposite: stay crawlable until the entries drop, then block. The
        // Site-Settings card says so at the moment the switch is flipped.
        //
        // The meta + X-Robots-Tag stay regardless. They cost nothing and they
        // are what still applies to a crawler that ignores robots.txt, or that
        // arrives holding a URL it already knew about.
        if ($site->development) {
            $out .= "# This site is in development: not crawlable, and not indexable.\n";
            $out .= "# Every page also carries noindex, nofollow (meta + X-Robots-Tag),\n";
            $out .= "# which is what still applies to anything that ignores this file.\n";
            $out .= "User-agent: *\nDisallow: /\n";

            return $out;
        }

        $out .= "User-agent: *\nAllow: /\n";
        if ($base !== '') {
            $out .= "\nSitemap: " . $base . "/sitemap.xml\n";
            $out .= '# LLM guide: ' . $base . "/llms.txt\n";
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
        $host = self::canonicalHostOf($site);
        $byline = $site->name . ' - published as fully static HTML by GetFastSite.com';

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

    /**
     * The page CSP for a script mode. Extracted so the `_headers` file the CDN
     * reads and the `.htaccess` a customer's own host reads are built from the
     * SAME string — two copies of this policy would drift the first time one
     * was relaxed.
     */
    public static function csp(string $scriptMode = 'none'): string
    {
        // 'none'   = zero-JS (default). 'inline' = only the lang-redirect inline
        // script. 'full' = user scripts opted in (self + inline + https + blob:
        // — embed loaders commonly boot their payload from a blob: URL, which
        // only a script the policy already allowed can have created — plus
        // eval: attribute-driven runtimes (Alpine, Petite-Vue, htmx extensions)
        // compile their expressions with `new Function()`, which CSP gates
        // behind 'unsafe-eval' SEPARATELY from 'unsafe-inline'. Withholding it
        // adds no real protection once https: + inline are allowed, but breaks
        // those runtimes with a per-directive EvalError only visible in the
        // console. 'full' means the site trusts its scripts; let them run.
        return match ($scriptMode) {
            'full' => "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: blob:; frame-ancestors 'self'",
            'inline' => "script-src 'unsafe-inline'; frame-ancestors 'self'",
            default => "script-src 'none'; frame-ancestors 'self'",
        };
    }

    /**
     * @param array<string, string> $pageModes url pattern → script mode, for
     *                                         pages needing more than the base
     */
    private function headers(string $scriptMode = 'none', bool $development = false, array $pageModes = []): string
    {
        // 'none'   = zero-JS (default). 'inline' = only the lang-redirect inline
        // script. 'full' = user scripts opted in (self + inline + https + blob:
        // — embed loaders commonly boot their payload from a blob: URL, which
        // only a script the policy already allowed can have created — plus
        // eval: attribute-driven runtimes (Alpine, Petite-Vue, htmx extensions)
        // compile their expressions with `new Function()`, which CSP gates
        // behind 'unsafe-eval' SEPARATELY from 'unsafe-inline'. Withholding it
        // adds no real protection once https: + inline are allowed, but breaks
        // those runtimes with a per-directive EvalError only visible in the
        // console. 'full' means the site trusts its scripts; let them run.
        $csp = self::csp($scriptMode);

        // Development mode: the header half of the noindex. The meta tag only
        // exists inside HTML, so without this a PDF in /files/ or an image in
        // /media/ is still perfectly indexable — and a PDF is exactly the kind
        // of thing that quietly ranks.
        $robots = $development ? "\n  X-Robots-Tag: noindex, nofollow" : '';

        $out = <<<TXT
            /*
              X-Content-Type-Options: nosniff
              Referrer-Policy: strict-origin-when-cross-origin
              Content-Security-Policy: {$csp}{$robots}
            /assets/*
              Cache-Control: public, max-age=31536000, immutable
            /media/*
              Cache-Control: public, max-age=31536000, immutable
            /files/*
              Cache-Control: public, max-age=31536000, immutable

            TXT;

        // Per-page overrides LAST. `_headers` applies every matching rule and
        // the last one wins per header name — which is true of Cloudflare
        // Pages and of our own `BuildHeaders` parser alike, so direct serving
        // and preview enforce the identical policy with no extra code. Order
        // is therefore load-bearing: emitted before `/*` these would be
        // overwritten by the site-wide default and do nothing at all.
        ksort($pageModes);
        foreach ($pageModes as $pattern => $mode) {
            $out .= $pattern . "\n  Content-Security-Policy: " . self::csp($mode) . "\n";
        }

        // One line ending for the whole file. The heredoc above carries
        // whatever the SOURCE file was checked out with — CRLF on Windows —
        // while everything appended here uses LF, so without this the shipped
        // artifact has mixed endings depending on which machine built it. Both
        // readers tolerate it, but a file whose bytes change with the build
        // host breaks byte-identical rebuilds, which is a restore guarantee.
        return str_replace("\r\n", "\n", $out);
    }

    /**
     * The `_headers` URL pattern(s) that address one output file.
     *
     * Two for a directory index, because `/blog/hello/` and
     * `/blog/hello/index.html` are both real requests for the same page and a
     * CSP that covers only one of them is a policy with a hole in it. The root
     * index needs the same pair (`/` and `/index.html`).
     *
     * @return list<string>
     */
    private static function headerPatterns(string $outputPath): array
    {
        if ($outputPath === 'index.html') {
            return ['/', '/index.html'];
        }
        if (str_ends_with($outputPath, '/index.html')) {
            $dir = '/' . substr($outputPath, 0, -\strlen('/index.html'));

            return [$dir . '/', $dir . '/index.html'];
        }

        return ['/' . $outputPath];
    }

    /** Numeric CSP demand → the mode name `csp()` understands. */
    private static function scriptModeName(int $level): string
    {
        return match (true) {
            $level >= 2 => 'full',
            $level === 1 => 'inline',
            default => 'none',
        };
    }

    /**
     * Cloudflare Pages evaluates _redirects BEFORE static assets, so a rule
     * whose from_path is a live page would shadow it — filter those out.
     *
     * @param list<string> $outputKeys e.g. "blog/x/index.html"
     * @param list<array{from_path: string, to_path: string, status: int}> $rows
     */
    private function redirectsFile(Site $site, array $outputKeys, array $rows): string
    {
        $livePaths = [];
        foreach ($outputKeys as $key) {
            $livePaths[$key === 'index.html' ? '/' : (str_ends_with($key, '/index.html') ? '/' . substr($key, 0, -10) : '/' . $key)] = true;
        }

        // NOTE: the www↔apex 301 is NOT emitted here. Cloudflare Pages
        // `_redirects` matches the request PATH only (never scheme/host) and
        // rejects the Netlify `!` forced-status flag, so a `https://www…/*`
        // source is a dead line. The `<link rel=canonical>` (built from the
        // site's canonical_host) is the mechanism Google honors for the dedup;
        // the host-level 301 is a Cloudflare zone Redirect Rule the operator
        // adds in git mode, and DirectServeGate performs it in direct mode.
        $lines = [];

        foreach ($rows as $row) {
            $from = (string) $row['from_path'];
            $status = (int) $row['status'];
            if (isset($livePaths[$from])) {
                $this->logger->warning('Redirect shadows a live page — omitted from _redirects', ['site' => $site->handle, 'from' => $from]);
                continue;
            }
            if ($status === RedirectService::GONE) {
                // `_redirects` expresses 3xx (and a 200 proxy) only — no line
                // answers 410. The rule ships as a comment so the deployed
                // artifact still records the intent, and the CDN falls through
                // to the site's 404 page. Direct serving returns a real 410.
                $lines[] = sprintf('# %s 410 Gone (CDN serves the 404 page; direct serving returns 410)', $from);
                continue;
            }
            $lines[] = sprintf('%s %s %d', $from, $row['to_path'], $status);
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Writes one batch of outputs through the shared build_manifest state:
     * unchanged files skipped, consumed manifest rows removed in place.
     * Batches exist so each locale can be flushed to disk (and its memory
     * freed) before the next one renders; whatever is left in $manifest after
     * the last batch is an orphan for {@see deleteOrphans}.
     *
     * @param array<string, string> $outputs path => content
     * @param array<string, string> $manifest path => rendered_sha256, consumed in place
     * @param array<string, true> $seenPaths appended in place — every path this build emitted
     * @param \Closure(): void $tick
     * @return array{int, int, int} [written, skipped, bytes]
     */
    private function writeBatch(Connection $db, string $outDir, array $outputs, bool $force, array &$manifest, array &$seenPaths, \Closure $tick): array
    {
        $written = 0;
        $skipped = 0;
        $bytes = 0;
        $now = $this->clock->iso();

        foreach ($outputs as $path => $content) {
            $seenPaths[$path] = true;
            $bytes += \strlen($content);
            $hash = hash('sha256', $content);
            $target = $outDir . '/' . $path;

            if (!$force && ($manifest[$path] ?? null) === $hash && is_file($target)) {
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
            if (($written % 100) === 0) {
                $tick();
            }
        }

        return [$written, $skipped, $bytes];
    }

    /**
     * Deletes the files whose build_manifest rows no batch of this build
     * consumed — built before, absent now.
     *
     * @param array<string, string> $manifest the leftover rows, path => sha
     */
    private function deleteOrphans(Connection $db, string $outDir, array $manifest): int
    {
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

        return $deleted;
    }
}
