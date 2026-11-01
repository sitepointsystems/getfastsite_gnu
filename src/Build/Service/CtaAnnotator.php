<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Content\Service\Slugger;

/**
 * GTM/analytics CTA attributes: every <a href>, <button> and submit/button
 * <input> on every rendered page carries
 *
 *   data-cta          stable snake_case identifier — the cross-language join
 *                     key. Derived from the href PATH with the locale prefix
 *                     stripped, so /demo and /da/demo both become "demo" and
 *                     one GTM report aggregates a CTA across every locale.
 *   data-cta-text     the actual visible text of the element (verbatim,
 *                     whitespace-collapsed) — per-locale by nature.
 *   data-cta-language the locale of the page the element sits on.
 *   data-cta-location where on the page it sits (see below).
 *
 * Explicitly authored attributes ALWAYS win — the pass only fills what is
 * missing, so it is idempotent and shells/content can override any value.
 *
 * data-cta-location cascades: a `data-cta-location` on ANY ancestor is
 * inherited by every link/button inside it (a shell marks `<section
 * data-cta-location="hero">` once). Unmarked containers fall back to
 * landmarks — header→header, nav→navigation, footer→footer, aside→sidebar,
 * a [data-fs-toc] nav→toc, a [data-fs-langnav] switcher→language_switcher —
 * with `content` as the last resort. The NEAREST ancestor wins.
 *
 * Runs as a build pass on the final rendered HTML (BuildService), so shell
 * markup, content bodies, verbatim data-fs-embed widgets and engine-rendered
 * links are all covered with one mechanism — retroactively for existing
 * sites on their next publish. The attributes are inert markup (the
 * sanitizer preserves all data-*); GTM's own click triggers read them, so
 * the zero-JS output contract is untouched.
 *
 * Deliberately a string pass (single left-to-right scan with an open-element
 * stack), NOT a DOM round trip: re-serializing minified HTML5 churns bytes
 * everywhere, and byte-identical rebuilds are a restore guarantee.
 */
final class CtaAnnotator
{
    /** Elements whose raw content is skipped wholesale (never annotated). */
    private const SKIP = ['script', 'style', 'noscript', 'template', 'textarea', 'svg', 'title'];

    /** Landmark element → implied data-cta-location (nearest ancestor wins). */
    private const LANDMARKS = ['header' => 'header', 'nav' => 'navigation', 'footer' => 'footer', 'aside' => 'sidebar'];

    /** Elements that never take a closing tag, so they never enter the stack. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const CTA_MAX = 80;
    private const TEXT_MAX = 160;

    /**
     * A tag's attribute segment, tolerating quoted `>` (data-values JSON).
     */
    private const TAG_ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /**
     * @param string $pageLocale the locale of the page being annotated
     * @param list<string> $locales every locale the site builds — used to
     *   strip the /{locale}/ prefix from hrefs so data-cta is identical on
     *   every translation of a page
     */
    public static function annotate(string $html, string $pageLocale, array $locales): string
    {
        $collect = null;

        return self::walk($html, $pageLocale, $locales, $collect);
    }

    /**
     * Audit companion: open-tag snippets of every link/button that LACKS any
     * of the four attributes, honoring exactly the same skip rules as
     * annotate(). After the build pass ran, a non-empty result is an
     * annotator bug (PageAudit FAILs on it).
     *
     * @return list<string>
     */
    public static function missing(string $html): array
    {
        $missing = [];
        self::walk($html, '', [], $missing);

        return $missing;
    }

    /**
     * @param list<string> $locales
     * @param list<string>|null $missing collect-only mode when non-null
     */
    private static function walk(string $html, string $pageLocale, array $locales, ?array &$missing): string
    {
        $len = \strlen($html);
        $out = '';
        $cursor = 0;
        /** @var list<array{tag: string, location: ?string, explicit: bool, hook: bool}> $stack */
        $stack = [];

        while ($cursor < $len) {
            $lt = strpos($html, '<', $cursor);
            if ($lt === false) {
                $out .= substr($html, $cursor);
                break;
            }
            $out .= substr($html, $cursor, $lt - $cursor);
            $cursor = $lt;

            // Comments pass through verbatim.
            if (substr($html, $cursor, 4) === '<!--') {
                $end = strpos($html, '-->', $cursor + 4);
                $end = $end === false ? $len : $end + 3;
                $out .= substr($html, $cursor, $end - $cursor);
                $cursor = $end;
                continue;
            }

            if (preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9-]*)(' . self::TAG_ATTRS . ')>/', $html, $m, 0, $cursor) !== 1) {
                // A stray "<" that opens no tag (doctype handled here too).
                $out .= $html[$cursor];
                ++$cursor;
                continue;
            }

            $whole = $m[0];
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $attrs = $m[3];

            if ($closing) {
                // Pop to the matching open tag; tolerate markup that closes
                // something never opened (pop nothing then).
                for ($i = \count($stack) - 1; $i >= 0; --$i) {
                    if ($stack[$i]['tag'] === $tag) {
                        array_splice($stack, $i);
                        break;
                    }
                }
                $out .= $whole;
                $cursor += \strlen($whole);
                continue;
            }

            // Raw/foreign content: emit up to and including the close tag,
            // annotating nothing inside.
            if (\in_array($tag, self::SKIP, true)) {
                $end = self::closeTagEnd($html, $tag, $cursor + \strlen($whole)) ?? $len;
                $out .= substr($html, $cursor, $end - $cursor);
                $cursor = $end;
                continue;
            }

            $selfClosing = str_ends_with(rtrim(substr($whole, 0, -1)), '/');
            $isTarget = $tag === 'a' || $tag === 'button'
                || ($tag === 'input' && preg_match('/\btype\s*=\s*["\']?(submit|button|image)\b/i', $attrs) === 1);

            if ($isTarget) {
                $inner = '';
                if ($tag !== 'input' && !$selfClosing) {
                    // Anchors/buttons don't legally nest themselves, so the
                    // next close tag is ours. The cursor is NOT advanced —
                    // children (icons, spans) still flow through the walk.
                    $close = self::closeTagStart($html, $tag, $cursor + \strlen($whole));
                    if ($close !== null) {
                        $inner = substr($html, $cursor + \strlen($whole), $close - $cursor - \strlen($whole));
                    }
                }
                if ($missing !== null) {
                    if (!self::hasAttr($attrs, 'data-cta') || !self::hasAttr($attrs, 'data-cta-text')
                        || !self::hasAttr($attrs, 'data-cta-language') || !self::hasAttr($attrs, 'data-cta-location')) {
                        $missing[] = mb_substr($whole, 0, 120);
                    }
                } else {
                    $location = self::attrValue($attrs, 'data-cta-location')
                        ?? self::inheritedLocation($stack, $tag, $attrs)
                        ?? 'content';
                    $whole = self::inject($whole, $tag, $attrs, $inner, $location, $pageLocale, $locales);
                }
            }

            $out .= $whole;
            $cursor += \strlen($m[0]);

            if (!$selfClosing && !\in_array($tag, self::VOID, true)) {
                $stack[] = [
                    'tag' => $tag,
                    'location' => self::containerLocation($tag, $attrs),
                    'explicit' => self::attrValue($attrs, 'data-cta-location') !== null,
                    'hook' => self::hasAttr($attrs, 'data-fs-hook'),
                ];
            }
        }

        return $out;
    }

    /** The location a CONTAINER contributes to its descendants, if any. */
    private static function containerLocation(string $tag, string $attrs): ?string
    {
        return self::attrValue($attrs, 'data-cta-location')
            ?? (self::hasAttr($attrs, 'data-fs-langnav') ? 'language_switcher' : null)
            ?? (self::hasAttr($attrs, 'data-fs-toc') ? 'toc' : null)
            ?? (self::LANDMARKS[$tag] ?? null);
    }

    /**
     * Nearest ancestor location. The element's OWN landmark-ish markers count
     * too (a <nav data-fs-langnav> link container is its own context).
     *
     * One refinement for component hooks: the engine's hook wrapper carries
     * an EXPLICIT data-cta-location (data-fs-hook marks it), and that beats
     * implied landmarks the injected component happens to render — a CTA box
     * built as an <aside> must report its hook placement, not "sidebar".
     * Outside hook wrappers the nearest ancestor wins exactly as before
     * (an implied landmark still shadows a farther explicit section marker).
     *
     * @param list<array{tag: string, location: ?string, explicit: bool, hook: bool}> $stack
     */
    private static function inheritedLocation(array $stack, string $tag, string $attrs): ?string
    {
        $own = self::hasAttr($attrs, 'data-fs-langnav') ? 'language_switcher' : null;
        if ($own !== null) {
            return $own;
        }
        $implied = null;
        for ($i = \count($stack) - 1; $i >= 0; --$i) {
            $entry = $stack[$i];
            if ($entry['location'] === null) {
                continue;
            }
            if ($entry['explicit']) {
                // Explicit and nearest → wins outright; when an implied
                // landmark sits nearer, only a hook wrapper overrides it.
                if ($implied === null || $entry['hook']) {
                    return $entry['location'];
                }

                return $implied;
            }
            $implied ??= $entry['location'];
        }

        return $implied;
    }

    /**
     * @param list<string> $locales
     */
    private static function inject(string $whole, string $tag, string $attrs, string $inner, string $location, string $pageLocale, array $locales): string
    {
        $text = self::visibleText($tag, $attrs, $inner);

        $add = '';
        if (!self::hasAttr($attrs, 'data-cta')) {
            $add .= ' data-cta="' . htmlspecialchars(self::ctaId($tag, $attrs, $text, $locales), ENT_QUOTES) . '"';
        }
        if (!self::hasAttr($attrs, 'data-cta-text')) {
            $add .= ' data-cta-text="' . htmlspecialchars(mb_substr($text, 0, self::TEXT_MAX), ENT_QUOTES) . '"';
        }
        if (!self::hasAttr($attrs, 'data-cta-language')) {
            $add .= ' data-cta-language="' . htmlspecialchars($pageLocale, ENT_QUOTES) . '"';
        }
        if (!self::hasAttr($attrs, 'data-cta-location')) {
            $add .= ' data-cta-location="' . htmlspecialchars($location, ENT_QUOTES) . '"';
        }
        if ($add === '') {
            return $whole;
        }

        // Insert before the tag's closing > (or />), preserving self-closing.
        if (str_ends_with($whole, '/>')) {
            return substr($whole, 0, -2) . $add . '/>';
        }

        return substr($whole, 0, -1) . $add . '>';
    }

    /**
     * The human-visible text of the element: inner text for a/button (icon
     * SVGs removed first — their <title> is not label text), the value/label
     * for an input, falling back through aria-label, an inner image's alt,
     * then the title attribute.
     */
    private static function visibleText(string $tag, string $attrs, string $inner): string
    {
        if ($tag === 'input') {
            $text = self::attrValue($attrs, 'value') ?? '';
        } else {
            $stripped = preg_replace('/<svg\b.*?<\/svg\s*>/is', ' ', $inner) ?? $inner;
            $text = strip_tags($stripped);
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text !== '') {
            return $text;
        }

        foreach (['aria-label', 'alt'] as $labelAttr) {
            $label = self::attrValue($attrs, $labelAttr);
            if ($label !== null && trim($label) !== '') {
                return trim($label);
            }
        }
        if ($tag !== 'input' && preg_match('/<img\b' . self::TAG_ATTRS . '\balt\s*=\s*"([^"]+)"/i', $inner, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $title = self::attrValue($attrs, 'title');

        return $title !== null ? trim($title) : '';
    }

    /**
     * The stable identifier. Href-first so every locale's variant of a page
     * yields the SAME id; text is the fallback for external/void targets.
     *
     * @param list<string> $locales
     */
    private static function ctaId(string $tag, string $attrs, string $text, array $locales): string
    {
        $href = $tag === 'a' ? self::attrValue($attrs, 'href') : null;
        $slug = null;

        if ($href !== null && trim($href) !== '' && trim($href) !== '#') {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_starts_with($href, '#')) {
                $slug = self::snake(substr($href, 1));
            } elseif (preg_match('#^https?://#i', $href) === 1) {
                $host = (string) parse_url($href, PHP_URL_HOST);
                $path = (string) parse_url($href, PHP_URL_PATH);
                $slug = self::snake($host . ' ' . $path);
            } elseif (preg_match('/^([a-z][a-z0-9+.-]*):(.*)$/i', $href, $m) === 1) {
                // mailto:, tel:, … — scheme + target keeps ids readable.
                $slug = self::snake($m[1] . ' ' . $m[2]);
            } else {
                // Internal path: strip query/fragment and the locale prefix so
                // /da/demo and /demo share one id; the root becomes "home".
                $path = (string) preg_replace('/[?#].*$/', '', $href);
                $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
                if ($segments !== [] && \in_array(strtolower($segments[0]), array_map('strtolower', $locales), true)) {
                    array_shift($segments);
                }
                $slug = $segments === [] ? 'home' : self::snake(implode(' ', $segments));
            }
        }

        if ($slug === null || $slug === '') {
            $slug = self::snake($text);
        }
        if ($slug === '') {
            $slug = $tag === 'a' ? 'link' : 'button';
        }

        return substr($slug, 0, self::CTA_MAX);
    }

    /** snake_case slug ("Book Demo" → book_demo), '' when nothing survives. */
    private static function snake(string $text): string
    {
        $slug = Slugger::slugify($text);

        return $slug === 'untitled' ? '' : str_replace('-', '_', $slug);
    }

    /**
     * Offset of the next `</tag>` (whitespace tolerated, `</a` never matches
     * `</abbr`), or null when the document never closes it.
     */
    private static function closeTagStart(string $html, string $tag, int $from): ?int
    {
        if (preg_match('/<\/' . $tag . '\s*>/i', $html, $m, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }

        return $m[0][1];
    }

    /** Offset just PAST the next `</tag>`, or null. */
    private static function closeTagEnd(string $html, string $tag, int $from): ?int
    {
        if (preg_match('/<\/' . $tag . '\s*>/i', $html, $m, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }

        return $m[0][1] + \strlen($m[0][0]);
    }

    private static function hasAttr(string $attrs, string $name): bool
    {
        // \s|^ guard: data-cta must not match inside data-cta-text etc.,
        // and the (?![\w-]) suffix guard covers valueless attributes.
        return preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?![\w-])/i', $attrs) === 1;
    }

    private static function attrValue(string $attrs, string $name): ?string
    {
        if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m) !== 1) {
            return null;
        }

        return $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
    }
}
