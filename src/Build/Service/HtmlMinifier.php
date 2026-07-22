<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

/**
 * Conservative HTML minifier: strips comments and collapses whitespace runs
 * to a single space, preserving <pre>, <textarea>, <script> and <style>
 * content verbatim. A single space between inline elements renders the same
 * as a newline, so this is rendering-safe.
 *
 * Two comments survive: IE conditionals, and the `<!--fs:languages-->`
 * placement marker — pages are minified at render, BEFORE the build's
 * language-switcher pass replaces that marker, so stripping it here would
 * silently swallow the agent's placement. (Any marker nothing replaces is
 * swept by BuildService after that pass, so it never ships.)
 */
final class HtmlMinifier
{
    public static function minify(string $html): string
    {
        $preserved = [];
        $html = preg_replace_callback(
            '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#si',
            static function (array $m) use (&$preserved): string {
                $key = "\x00FSPRE" . \count($preserved) . "\x00";
                $preserved[$key] = $m[0];

                return $key;
            },
            $html,
        ) ?? $html;

        $html = preg_replace('/<!--(?!\[if|fs:languages).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;

        return trim(strtr($html, $preserved));
    }
}
