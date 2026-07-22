<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Translates text (plain or HTML) from one locale to another, preserving
 * markup structure. Implementations: EndpointrTranslator (the AI gateway)
 * and NullTranslator (when unconfigured — callers mark the row failed).
 */
interface Translator
{
    /**
     * @param string $format 'html' | 'text'
     * @throws TranslationException on an unrecoverable provider error
     */
    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string;

    public function isConfigured(): bool;
}
