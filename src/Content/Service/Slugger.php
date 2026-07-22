<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

final class Slugger
{
    /**
     * Danish/Norwegian conventions applied before ICU, which would otherwise
     * flatten ø→o and å→a. The owner's sites are Danish-first.
     */
    private const NORDIC = [
        'æ' => 'ae', 'Æ' => 'ae',
        'ø' => 'oe', 'Ø' => 'oe',
        'å' => 'aa', 'Å' => 'aa',
        'ß' => 'ss',
    ];

    public static function slugify(string $text): string
    {
        $text = strtr($text, self::NORDIC);

        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($transliterator !== null) {
            $text = $transliterator->transliterate($text) ?: $text;
        } else {
            $text = mb_strtolower($text);
        }

        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? substr($text, 0, 120) : 'untitled';
    }

    /**
     * Path-aware slug: preserves `/` boundaries so a ported URL structure
     * (e.g. "help/getting-started") survives, slugifying each segment
     * independently. No empty/leading/trailing segments, so the result is a
     * safe relative path — never "..", "//" or an absolute path.
     */
    public static function slugifyPath(string $text): string
    {
        $segments = [];
        foreach (explode('/', $text) as $segment) {
            if (trim($segment) === '') {
                continue; // collapse //, drop leading/trailing /
            }
            $slug = self::slugify($segment);
            if ($slug !== 'untitled') {
                $segments[] = $slug; // drops "..", "!!!", etc. → no path traversal
            }
        }

        return $segments === [] ? 'untitled' : implode('/', $segments);
    }
}
