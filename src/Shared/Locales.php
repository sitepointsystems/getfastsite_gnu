<?php

declare(strict_types=1);

namespace Fastsite\Shared;

/**
 * Locale metadata for the dashboard: human name + flag emoji per code. The
 * two-letter (or `xx-yy`) code stays the internal value everywhere; only the
 * display swaps to name/flag.
 */
final class Locales
{
    /** code => [English name, ISO-3166 country for the flag] */
    private const MAP = [
        'en' => ['English', 'GB'], 'en-us' => ['English (US)', 'US'], 'en-gb' => ['English (UK)', 'GB'],
        'da' => ['Danish', 'DK'], 'de' => ['German', 'DE'], 'nl' => ['Dutch', 'NL'],
        'sv' => ['Swedish', 'SE'], 'nb' => ['Norwegian', 'NO'], 'no' => ['Norwegian', 'NO'], 'nn' => ['Norwegian Nynorsk', 'NO'],
        'fi' => ['Finnish', 'FI'], 'is' => ['Icelandic', 'IS'],
        'fr' => ['French', 'FR'], 'es' => ['Spanish', 'ES'], 'it' => ['Italian', 'IT'],
        'pt' => ['Portuguese', 'PT'], 'pt-br' => ['Portuguese (Brazil)', 'BR'],
        'pl' => ['Polish', 'PL'], 'cs' => ['Czech', 'CZ'], 'sk' => ['Slovak', 'SK'],
        'hu' => ['Hungarian', 'HU'], 'ro' => ['Romanian', 'RO'], 'bg' => ['Bulgarian', 'BG'],
        'el' => ['Greek', 'GR'], 'tr' => ['Turkish', 'TR'], 'ru' => ['Russian', 'RU'],
        'uk' => ['Ukrainian', 'UA'], 'ar' => ['Arabic', 'SA'], 'he' => ['Hebrew', 'IL'],
        'ja' => ['Japanese', 'JP'], 'ko' => ['Korean', 'KR'], 'zh' => ['Chinese', 'CN'],
        'hi' => ['Hindi', 'IN'], 'th' => ['Thai', 'TH'], 'vi' => ['Vietnamese', 'VN'],
        'id' => ['Indonesian', 'ID'], 'ms' => ['Malay', 'MY'], 'et' => ['Estonian', 'EE'],
        'lv' => ['Latvian', 'LV'], 'lt' => ['Lithuanian', 'LT'], 'sl' => ['Slovenian', 'SI'],
        'hr' => ['Croatian', 'HR'], 'sr' => ['Serbian', 'RS'], 'ca' => ['Catalan', 'ES'],
    ];

    public static function name(string $code): string
    {
        return self::MAP[strtolower($code)][0] ?? strtoupper($code);
    }

    /** ISO-3166 country code (lowercase) for the flag SVG, or '' if unknown. */
    public static function country(string $code): string
    {
        return strtolower(self::MAP[strtolower($code)][1] ?? '');
    }

    /**
     * URL of the real flag SVG in public/flags/ (country-code named), or ''
     * when the locale has no mapped country. Served at /flags/{cc}.svg in dev
     * (public/router.php) and prod (nginx root /app/public).
     */
    public static function flagUrl(string $code): string
    {
        $cc = self::country($code);

        return $cc !== '' ? '/flags/' . $cc . '.svg' : '';
    }

    /** Flag emoji from the mapped country (regional-indicator letters), or a neutral flag. */
    public static function flag(string $code): string
    {
        $cc = self::MAP[strtolower($code)][1] ?? '';
        if (\strlen($cc) !== 2) {
            return "\u{1F3F3}\u{FE0F}"; // white flag
        }
        $cc = strtoupper($cc);

        return mb_chr(0x1F1E6 + \ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + \ord($cc[1]) - 65, 'UTF-8');
    }

    /** "🇩🇰 Danish" */
    public static function label(string $code): string
    {
        return self::flag($code) . ' ' . self::name($code);
    }

    /**
     * Selectable locales for the add-language dropdown, excluding the ones
     * already used, as code => label.
     *
     * @param list<string> $exclude
     * @return array<string, string>
     */
    public static function options(array $exclude = []): array
    {
        $exclude = array_map('strtolower', $exclude);
        $seen = [];
        $out = [];
        foreach (self::MAP as $code => [$name, $cc]) {
            if (\in_array($code, $exclude, true) || isset($seen[$name])) {
                continue; // skip used codes + duplicate names (no/nb both "Norwegian")
            }
            $seen[$name] = true;
            $out[$code] = self::flag($code) . ' ' . $name;
        }

        return $out;
    }

    /**
     * Selectable locale codes (deduped by language name), for a custom flag
     * dropdown that renders the flag + name itself via the Twig helpers.
     *
     * @param list<string> $exclude
     * @return list<string>
     */
    public static function codes(array $exclude = []): array
    {
        $exclude = array_map('strtolower', $exclude);
        $seen = [];
        $out = [];
        foreach (self::MAP as $code => [$name, $cc]) {
            if (\in_array($code, $exclude, true) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $code;
        }

        return $out;
    }
}
