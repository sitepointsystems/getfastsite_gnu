<?php

declare(strict_types=1);

namespace Fastsite\Media;

/**
 * Writes alt text for an image with a vision-capable model on the site's
 * OpenAI-compatible AI gateway (the same OpenRouter credential translation /
 * fact-checking use; the general `model` must accept image input — the
 * defaults do). Fail-safe by contract: any error returns null and the caller
 * falls back to a filename-derived alt — an upload must never fail over alt.
 */
final class AltTextGenerator
{
    /** Never base64 a huge original into a prompt; callers send a small variant. */
    private const MAX_IMAGE_BYTES = 4_194_304; // 4 MB

    /**
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $transport
     *        test seam (chat body) → decoded response; defaults to curl.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 45,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * Alt text for the image bytes (≤ ~125 chars, single line), or null when
     * unconfigured / the image is too large to inline / the call fails.
     */
    public function generate(string $imageBytes, string $mime, string $context = ''): ?string
    {
        if (!$this->isConfigured() || $imageBytes === '' || \strlen($imageBytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        try {
            $response = $this->post([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $context !== '' ? 'Context (filename/title): ' . $context : 'Write the alt text.'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($imageBytes)]],
                    ]],
                ],
                'temperature' => 0.2,
                'max_tokens' => 300,
            ]);
        } catch (\Throwable) {
            return null; // fail-safe — the caller falls back
        }

        $out = $response['choices'][0]['message']['content'] ?? null;
        if (!\is_string($out)) {
            return null;
        }
        // One clean line: no fences, no wrapping quotes, no trailing period rules
        // beyond the model's own — just normalize whitespace and cap length.
        $out = trim((string) preg_replace('/\s+/', ' ', strip_tags($out)));
        $out = trim($out, "\"'`“”");
        if ($out === '') {
            return null;
        }

        return mb_substr($out, 0, 300);
    }

    private function systemPrompt(): string
    {
        return "You write alt text for images on websites.\n"
            . "- Describe what the image shows, concretely and concisely, in at most 125 characters.\n"
            . "- Plain sentence case; no 'image of' / 'picture of' prefixes; no quotes, markdown, or commentary.\n"
            . "- Reply with the alt text ONLY.";
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($body);
        }

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
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Alt-text gateway returned HTTP ' . $status);
        }
        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Alt-text gateway returned non-JSON.');
        }

        return $decoded;
    }
}
