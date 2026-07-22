<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Translates via OpenRouter's OpenAI-compatible chat API (the open-source
 * build's AI provider — swap-in for {@see EndpointrTranslator}).
 *   POST {AI_BASE_URL:-https://openrouter.ai/api}/v1/chat/completions
 *   Authorization: Bearer {AI_API_KEY}
 *   body: {model, messages:[system,user], temperature, max_tokens}
 *   response: {choices:[{message:{content}}]}
 * Same common {@see Translator} contract + same AI_API_KEY / AI_BASE_URL env
 * as endpointr, so the two are a one-line provider swap at deploy.
 */
final class OpenRouterTranslator implements Translator
{
    private const LOCALE_NAMES = [
        'en' => 'English', 'da' => 'Danish', 'de' => 'German', 'es' => 'Spanish',
        'fr' => 'French', 'it' => 'Italian', 'nb' => 'Norwegian Bokmål', 'nl' => 'Dutch',
        'sv' => 'Swedish', 'pl' => 'Polish', 'pt' => 'Portuguese', 'fi' => 'Finnish',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'openai/gpt-4o-mini',
        private readonly int $timeoutSeconds = 420,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        if (!$this->isConfigured()) {
            throw new TranslationException('OpenRouter translator is not configured.');
        }
        if (trim($content) === '') {
            return $content;
        }

        $response = $this->post([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($fromLocale, $toLocale, $format)],
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0.2,
            'max_tokens' => 16384,
        ]);

        $translated = $response['choices'][0]['message']['content'] ?? null;
        if (!\is_string($translated) || trim($translated) === '') {
            throw new TranslationException('OpenRouter returned no translation content.');
        }

        return $this->stripCodeFences($translated);
    }

    private function systemPrompt(string $from, string $to, string $format): string
    {
        $fromName = self::LOCALE_NAMES[$from] ?? $from;
        $toName = self::LOCALE_NAMES[$to] ?? $to;

        $rules = $format === 'html'
            ? "The input is an HTML fragment.\n"
                . "- Preserve every HTML tag, attribute, and attribute value EXACTLY (including data-media-id, href, class, src, width, height).\n"
                . "- Translate ONLY human-readable text between tags and translatable attributes (alt, title).\n"
                . "- Do NOT translate URLs, email addresses, code, or slugs."
            : "The input is a short plain-text string. Return only its translation.";

        return "You are a professional website translator. Translate from {$fromName} to {$toName} ({$to}).\n"
            . $rules . "\n"
            . "- Keep the same meaning, tone, and structure. Do not add or remove content.\n"
            . "- Do NOT translate brand or product names.\n"
            . "- Return ONLY the translation, with no code fences, no commentary, no surrounding quotes.";
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $base = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'https://openrouter.ai/api';
        $ch = curl_init($base . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new TranslationException('OpenRouter request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new TranslationException(sprintf('OpenRouter returned HTTP %d: %s', $status, mb_substr((string) $raw, 0, 200)));
        }

        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new TranslationException('OpenRouter returned a non-JSON response.');
        }

        return $decoded;
    }

    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\n(.*)\n```$/is', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
}
