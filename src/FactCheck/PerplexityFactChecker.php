<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

/**
 * Fact-checks content with a web-grounded Perplexity model via the existing
 * OpenAI-compatible AI gateway (OpenRouter):
 *   POST {AI_BASE_URL:-https://openrouter.ai/api}/v1/chat/completions
 *   Authorization: Bearer {AI_API_KEY}
 *   body: {model, messages:[system,user], ...}
 * Model defaults to `perplexity/sonar` (FACTCHECK_MODEL). Reuses the same
 * AI_API_KEY / AI_BASE_URL as translation — no separate credential.
 */
final class PerplexityFactChecker implements FactChecker
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'perplexity/sonar',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function describe(): string
    {
        return 'Perplexity (' . $this->model . ') via ' . ($this->baseUrl !== '' ? $this->baseUrl : 'openrouter.ai');
    }

    public function check(string $text, string $context = ''): FactCheckReport
    {
        if (!$this->isConfigured()) {
            throw new FactCheckException('Fact-checker is not configured.');
        }
        $text = trim($text);
        if ($text === '') {
            return new FactCheckReport('No text to fact-check.', [], $this->model);
        }
        // Keep the prompt bounded — fact-check the substance, not every word.
        $text = mb_substr($text, 0, 12000);

        $response = $this->post([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => ($context !== '' ? "Title/context: {$context}\n\n" : '') . "Content to fact-check:\n\n" . $text],
            ],
            'temperature' => 0.1,
            'max_tokens' => 4096,
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || trim($content) === '') {
            throw new FactCheckException('Fact-checker returned no content.');
        }

        return FactCheckReport::fromJson($this->extractJson($content), $this->model);
    }

    private function systemPrompt(): string
    {
        return "You are a rigorous fact-checker with live web access. From the content, extract the concrete, objectively CHECKABLE factual claims — prices, statistics, dates, specifications, records, and expert/product claims. Verify each against current, reputable web sources.\n"
            . "Return ONLY minified JSON (no markdown, no commentary) shaped exactly as:\n"
            . '{"summary":"1-2 sentence overall assessment","claims":[{"claim":"the claim as stated","verdict":"supported|contradicted|unverified","confidence":"high|medium|low","note":"what sources say, or the correct value","sources":["https://..."]}]}' . "\n"
            . "Rules: only include objectively checkable claims; if there are none, return an empty claims array. Never invent sources or facts. Prefer primary/official sources.";
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/is', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        // Isolate the outermost JSON object if the model wrapped it in prose.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $decoded = json_decode($text, true);

        return \is_array($decoded) ? $decoded : ['summary' => 'The fact-check response could not be parsed.', 'claims' => []];
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
            throw new FactCheckException('Fact-check request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new FactCheckException(sprintf('Fact-check gateway returned HTTP %d: %s', $status, mb_substr((string) $raw, 0, 200)));
        }

        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new FactCheckException('Fact-check gateway returned a non-JSON response.');
        }

        return $decoded;
    }
}
