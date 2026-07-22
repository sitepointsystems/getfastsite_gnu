<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

/**
 * Rewrites an HTML article fragment to apply fact-check corrections, using the
 * site's OpenAI-compatible AI gateway (the same OpenRouter credential the
 * fact-checker uses). It changes ONLY the wrong facts and preserves all markup;
 * the caller re-sanitizes the output (the model is untrusted).
 */
final class ContentCorrector
{
    /**
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $transport
     *        test seam (chat body) → decoded response; defaults to curl.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'openai/gpt-4o-mini',
        private readonly int $timeoutSeconds = 90,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * Returns the corrected HTML, or the original unchanged when there is
     * nothing to fix / it isn't configured / the call fails (fail-safe: never
     * lose the author's content over a correction).
     *
     * @param list<array{claim:string, verdict:string, note:string, sources?:list<string>}> $claims
     */
    public function rewrite(string $bodyHtml, string $title, array $claims): string
    {
        if (!$this->isConfigured() || trim($bodyHtml) === '' || $claims === []) {
            return $bodyHtml;
        }

        $lines = [];
        foreach ($claims as $i => $c) {
            $note = trim((string) ($c['note'] ?? ''));
            $lines[] = ($i + 1) . '. [' . ($c['verdict'] ?? 'unverified') . '] "' . ($c['claim'] ?? '') . '"'
                . ($note !== '' ? ' — correction: ' . $note : '');
        }
        $corrections = implode("\n", $lines);

        try {
            $response = $this->post([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => "Title: {$title}\n\nCorrections to apply:\n{$corrections}\n\nHTML to correct:\n{$bodyHtml}"],
                ],
                'temperature' => 0.1,
                'max_tokens' => 16384,
            ]);
        } catch (FactCheckException) {
            return $bodyHtml; // fail-safe
        }

        $out = $response['choices'][0]['message']['content'] ?? ($response['data']['content'] ?? null);
        if (!\is_string($out) || trim($out) === '') {
            return $bodyHtml;
        }

        return $this->stripCodeFences($out);
    }

    private function systemPrompt(): string
    {
        return "You are a meticulous editor fixing factual errors in an HTML article fragment.\n"
            . "- Apply ONLY the listed corrections. For a 'contradicted' claim, replace the wrong fact with the correct value from its note. For an 'unverified' claim, soften or qualify it so it is no longer stated as certain fact (do not invent specifics).\n"
            . "- Preserve every HTML tag, attribute, and attribute value EXACTLY (including data-media-id, href, class, src, width, height). Do not add, remove, or reorder markup.\n"
            . "- Change as little text as possible; keep tone, structure, and everything unrelated identical.\n"
            . "- Return ONLY the corrected HTML fragment — no code fences, no commentary, no surrounding quotes.";
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new FactCheckException('Correction request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new FactCheckException(sprintf('Correction gateway returned HTTP %d: %s', $status, mb_substr((string) $raw, 0, 200)));
        }

        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new FactCheckException('Correction gateway returned a non-JSON response.');
        }

        return $decoded;
    }

    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/is', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
}
