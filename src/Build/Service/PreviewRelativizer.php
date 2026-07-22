<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

/**
 * Rewrites a built page's absolute internal paths to RELATIVE ones, by the
 * page's directory depth, so a preview tree is self-contained and viewable
 * from ANY base (a preview URL/subpath or file://) without a deploy.
 *
 * Absolute base-URL links (canonical/OG) are first reduced to root-absolute,
 * then every root-absolute href/src/srcset is prefixed with the right number
 * of ../. Protocol-relative (//…), external (http…), fragment (#…) and data:
 * URLs are left untouched. Production output is never touched — this runs only
 * for `buildPreview`.
 */
final class PreviewRelativizer
{
    /**
     * @param string $relPath the page's path within the output tree,
     *                        e.g. "index.html", "features/index.html",
     *                        "blog/slug/index.html"
     */
    public static function relativize(string $html, string $relPath, string $baseUrl): string
    {
        $dir = trim(str_replace('\\', '/', \dirname($relPath)), '/.');
        $depth = $dir === '' ? 0 : substr_count($dir, '/') + 1;
        $prefix = $depth === 0 ? './' : str_repeat('../', $depth);

        $base = rtrim($baseUrl, '/');
        if ($base !== '') {
            $html = str_replace('="' . $base . '/', '="/', $html);
            $html = str_replace('="' . $base . '"', '="/"', $html);
        }

        $html = preg_replace_callback(
            '/\b(href|src)="\/(?!\/)([^"]*)"/i',
            static fn (array $m): string => $m[1] . '="' . $prefix . $m[2] . '"',
            $html,
        ) ?? $html;

        return preg_replace_callback(
            '/\b(srcset|imagesrcset)="([^"]*)"/i',
            static function (array $m) use ($prefix): string {
                $v = preg_replace('/(^|,\s*)\/(?!\/)/', '${1}' . $prefix, $m[2]) ?? $m[2];

                return $m[1] . '="' . $v . '"';
            },
            $html,
        ) ?? $html;
    }
}
