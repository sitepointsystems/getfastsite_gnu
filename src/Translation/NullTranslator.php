<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Used when no translation provider is configured. Every call fails so the
 * translation row is marked failed rather than silently shipping source
 * text as if it were translated.
 */
final class NullTranslator implements Translator
{
    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        throw new TranslationException('No translation provider configured (set ENDPOINTR_URL and ENDPOINTR_KEY).');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
