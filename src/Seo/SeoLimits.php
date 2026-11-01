<?php

declare(strict_types=1);

namespace Fastsite\Seo;

/**
 * The Google/Yoast length envelope for search snippets. Characters, not
 * pixels — the industry-standard approximation Yoast itself surfaces:
 * titles are truncated around 60 chars (~580px), descriptions around 156
 * (~990px); a description under ~120 wastes the snippet.
 */
final class SeoLimits
{
    public const TITLE_MAX = 60;
    public const TITLE_MIN = 30;
    public const DESC_MAX = 156;
    public const DESC_MIN = 120;

    /**
     * Cuts at a word boundary so a hard-truncated value still reads clean.
     * Returns the input unchanged when it already fits.
     */
    public static function truncate(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t.,;:–—-");
    }
}
