<?php

declare(strict_types=1);

namespace Fastsite\Translation;

use Fastsite\Shared\Locales;

/**
 * The system prompt every translator sends, and the response cleanup every
 * translator applies. Both {@see EndpointrTranslator} and
 * {@see OpenRouterTranslator} used to carry byte-identical copies of these
 * plus their own truncated locale-name table — so a locale the dashboard
 * happily offered (Hindi, Ukrainian) reached the model as a bare code. One
 * copy, and locale names come from {@see Locales}, which is the same table
 * the language picker uses.
 *
 * Three formats:
 *  - 'html'   an HTML fragment (body, intro/tldr/conclusion)
 *  - 'text'   one short plain string (a shell UI string)
 *  - 'fields' a JSON object of short metadata strings, translated in ONE call
 *
 * 'fields' is a throughput decision. A page's title, excerpt, seo_title and
 * seo_description are four tiny strings that used to cost four round trips —
 * on an 82-page × 9-locale site that alone is ~3000 calls of pure latency.
 * They share one call, and {@see TranslationService} falls back to individual
 * calls if a model ever returns something that is not the expected object.
 */
final class TranslationPrompt
{
    public const FORMAT_HTML = 'html';
    public const FORMAT_TEXT = 'text';
    public const FORMAT_FIELDS = 'fields';

    /**
     * Locales whose {@see Locales} display name is deliberately looser than
     * what a translation prompt needs (the picker says "Norwegian"; the model
     * must be told which written standard).
     */
    private const PRECISE_NAMES = [
        'nb' => 'Norwegian Bokmål',
        'no' => 'Norwegian Bokmål',
        'nn' => 'Norwegian Nynorsk',
    ];

    public static function localeName(string $code): string
    {
        return self::PRECISE_NAMES[strtolower($code)] ?? Locales::name($code);
    }

    public static function system(string $from, string $to, string $format): string
    {
        $fromName = self::localeName($from);
        $toName = self::localeName($to);

        $rules = match ($format) {
            self::FORMAT_FIELDS => "The input is a JSON object of short website metadata strings.\n"
                . "- Return a JSON object with EXACTLY the same keys, in the same order.\n"
                . "- Translate only the VALUES. Never translate, rename, add, or drop a key.\n"
                . "- Values are plain text: no HTML, no markdown, no surrounding quotes inside the value.\n"
                . '- Keep each value close to the source length — these are page titles and meta descriptions.',
            self::FORMAT_HTML => "The input is an HTML fragment.\n"
                . "- Preserve every HTML tag, attribute, and attribute value EXACTLY (including data-media-id, href, class, src, width, height).\n"
                . "- Translate ONLY human-readable text between tags and translatable attributes (alt, title).\n"
                . '- Do NOT translate URLs, email addresses, code, or slugs.',
            default => 'The input is a short plain-text string. Return only its translation.',
        };

        $shape = $format === self::FORMAT_FIELDS
            ? 'Return ONLY the JSON object, with no code fences, no commentary, and no surrounding text.'
            : 'Return ONLY the translation, with no code fences, no commentary, no surrounding quotes.';

        return "You are a professional website translator. Translate from {$fromName} to {$toName} ({$to}).\n"
            . $rules . "\n"
            . "- Keep the same meaning, tone, and structure. Do not add or remove content.\n"
            . "- Do NOT translate brand or product names.\n"
            . $shape;
    }

    /** A model occasionally wraps output in ``` fences despite instructions. */
    public static function stripCodeFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\n(.*)\n```$/is', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
}
