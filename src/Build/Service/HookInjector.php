<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Component\Dto\ComponentHook;

/**
 * Applies component hooks to one page body BEFORE the expansion chain runs:
 * each hook becomes a `<div data-fs-component>` placeholder inserted at its
 * position, so rendering, the per-(values, locale) cache, component CSS
 * inlining and the zero-JS strip all work exactly as for a hand-embedded
 * component. The wrapper carries data-fs-hook="{id}" (a styling/debugging
 * anchor) and data-cta-location (so GTM reports separate "after first
 * paragraph" from "bottom" CTAs via the existing CtaAnnotator cascade).
 *
 * Positions anchor on TOP-LEVEL paragraphs — a `</p>` inside a blockquote,
 * list or figure never counts, so a CTA can't land inside a quote. Deliberately
 * a string pass with an open-element stack (the CtaAnnotator rationale): no
 * DOM round trip, byte-stable output. A body stored verbatim as one
 * `<div data-fs-embed>` wrapper is entered — its direct paragraphs anchor the
 * positions and top/bottom insert just inside the wrapper — because trusted
 * ports are exactly the sites that want CTAs without editing ported HTML.
 *
 * Out-of-range positions clamp: "after paragraph 4" on a 2-paragraph page
 * inserts at the bottom rather than dropping the CTA.
 */
final class HookInjector
{
    /** Raw-content elements whose inside is never parsed (a </p> in a script string is text). */
    private const SKIP = ['script', 'style', 'noscript', 'template', 'textarea', 'svg', 'title'];

    /** Void elements never enter the open stack. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** A tag's attribute segment, tolerating quoted `>` (data-values JSON). */
    private const TAG_ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /**
     * @param list<ComponentHook> $hooks already filtered for this page
     *   (ComponentHook::appliesTo), in priority order
     * @param string $locale the locale of the page variant being rendered —
     *   passed into the component values as `locale` (unless the hook sets
     *   its own) so a react CTA can localize itself
     */
    public static function inject(string $bodyHtml, array $hooks, string $locale): string
    {
        if ($hooks === []) {
            return $bodyHtml;
        }

        $anchors = self::analyze($bodyHtml);

        /** @var list<array{offset: int, html: string}> $insertions */
        $insertions = [];
        foreach ($hooks as $hook) {
            $insertions[] = ['offset' => self::offsetFor($hook, $anchors), 'html' => self::markup($hook, $locale)];
        }
        // Stable sort: hooks at the same offset keep their priority order.
        usort($insertions, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $out = '';
        $cursor = 0;
        foreach ($insertions as $ins) {
            $out .= substr($bodyHtml, $cursor, $ins['offset'] - $cursor) . $ins['html'];
            $cursor = $ins['offset'];
        }

        return $out . substr($bodyHtml, $cursor);
    }

    /**
     * @param array{candidates: list<int>, top: int, bottom: int} $anchors
     */
    private static function offsetFor(ComponentHook $hook, array $anchors): int
    {
        $candidates = $anchors['candidates'];

        return match ($hook->position) {
            'top' => $anchors['top'],
            'after_paragraph' => $candidates[$hook->paragraph - 1] ?? $anchors['bottom'],
            'middle' => $candidates === []
                ? $anchors['bottom']
                : $candidates[(int) ceil(\count($candidates) / 2) - 1],
            default => $anchors['bottom'],
        };
    }

    private static function markup(ComponentHook $hook, string $locale): string
    {
        $values = $hook->values;
        $values['locale'] ??= $locale;
        $json = json_encode($values, JSON_THROW_ON_ERROR);

        return '<div data-fs-hook="' . $hook->id . '"'
            . ' data-cta-location="' . htmlspecialchars($hook->locationLabel(), ENT_QUOTES) . '">'
            . '<div data-fs-component="' . htmlspecialchars($hook->componentHandle, ENT_QUOTES) . '"'
            . ' data-values="' . htmlspecialchars($json, ENT_QUOTES) . '"></div></div>';
    }

    /**
     * One scan over the body: offsets just past each top-level `</p>` (the
     * after_paragraph/middle anchors) plus where `top` and `bottom` insert.
     * When the whole body is a single verbatim `<div data-fs-embed>` wrapper,
     * "top level" means the wrapper's direct children and top/bottom insert
     * inside it — a hook must never break out of a verbatim region's markup.
     *
     * @return array{candidates: list<int>, top: int, bottom: int}
     */
    private static function analyze(string $html): array
    {
        $len = \strlen($html);
        $cursor = 0;
        /** @var list<string> $stack open element names */
        $stack = [];
        /** @var list<array{depth: int, offset: int}> $pCloses */
        $pCloses = [];

        $rootElements = 0;
        $rootText = false;
        $firstRoot = null; // ['embed' => bool, 'afterOpen' => int, 'closeStart' => ?int]

        while ($cursor < $len) {
            $lt = strpos($html, '<', $cursor);
            if ($lt === false) {
                $rootText = $rootText || ($stack === [] && trim(substr($html, $cursor)) !== '');
                break;
            }
            if ($lt > $cursor) {
                $rootText = $rootText || ($stack === [] && trim(substr($html, $cursor, $lt - $cursor)) !== '');
                $cursor = $lt;
            }

            if (substr($html, $cursor, 4) === '<!--') {
                $end = strpos($html, '-->', $cursor + 4);
                $cursor = $end === false ? $len : $end + 3;
                continue;
            }

            if (preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9-]*)(' . self::TAG_ATTRS . ')>/', $html, $m, 0, $cursor) !== 1) {
                $rootText = $rootText || $stack === [];
                ++$cursor;
                continue;
            }

            $whole = $m[0];
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $attrs = $m[3];

            if ($closing) {
                for ($i = \count($stack) - 1; $i >= 0; --$i) {
                    if ($stack[$i] === $tag) {
                        if ($tag === 'p') {
                            $pCloses[] = ['depth' => $i, 'offset' => $cursor + \strlen($whole)];
                        }
                        if ($i === 0 && $firstRoot !== null && $firstRoot['closeStart'] === null && $rootElements === 1) {
                            $firstRoot['closeStart'] = $cursor;
                        }
                        array_splice($stack, $i);
                        break;
                    }
                }
                $cursor += \strlen($whole);
                continue;
            }

            if (\in_array($tag, self::SKIP, true)) {
                if ($stack === []) {
                    ++$rootElements;
                }
                if (preg_match('/<\/' . $tag . '\s*>/i', $html, $c, PREG_OFFSET_CAPTURE, $cursor + \strlen($whole)) === 1) {
                    $cursor = $c[0][1] + \strlen($c[0][0]);
                } else {
                    $cursor = $len;
                }
                continue;
            }

            $selfClosing = str_ends_with(rtrim(substr($whole, 0, -1)), '/');

            if ($stack === []) {
                ++$rootElements;
                if ($firstRoot === null && !$selfClosing && !\in_array($tag, self::VOID, true)) {
                    $firstRoot = [
                        'embed' => $tag === 'div' && preg_match('/(?:^|\s)data-fs-embed(?![\w-])/i', $attrs) === 1,
                        'afterOpen' => $cursor + \strlen($whole),
                        'closeStart' => null,
                    ];
                }
            }

            $cursor += \strlen($whole);
            if (!$selfClosing && !\in_array($tag, self::VOID, true)) {
                $stack[] = $tag;
            }
        }

        $wrapped = $rootElements === 1 && !$rootText
            && $firstRoot !== null && $firstRoot['embed'] && $firstRoot['closeStart'] !== null;

        $baseDepth = $wrapped ? 1 : 0;
        $candidates = [];
        foreach ($pCloses as $p) {
            if ($p['depth'] === $baseDepth) {
                $candidates[] = $p['offset'];
            }
        }

        return [
            'candidates' => $candidates,
            'top' => $wrapped ? $firstRoot['afterOpen'] : 0,
            'bottom' => $wrapped ? (int) $firstRoot['closeStart'] : $len,
        ];
    }
}
