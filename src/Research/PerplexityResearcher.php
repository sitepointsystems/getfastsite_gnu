<?php

declare(strict_types=1);

namespace Fastsite\Research;

/**
 * Researches a topic with a web-grounded Perplexity model via the same
 * OpenAI-compatible AI gateway (OpenRouter) as fact-checking:
 *   POST {baseUrl:-https://openrouter.ai/api}/v1/chat/completions
 *   Authorization: Bearer {apiKey}
 *   body: {model, messages:[system,user], ...}
 * Model defaults to `perplexity/sonar` (ai.research_model). Returns the model's
 * answer verbatim as markdown — no JSON parsing — for a writer to draft from.
 */
final class PerplexityResearcher implements Researcher
{
    /**
     * @param ?\Closure(array<string, mixed>): array<string, mixed> $transport test seam; real curl when null
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'perplexity/sonar',
        private readonly int $timeoutSeconds = 90,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function research(string $topic): string
    {
        if (!$this->isConfigured()) {
            throw new ResearchException('Researcher is not configured.');
        }
        $topic = trim($topic);
        if ($topic === '') {
            throw new ResearchException('No topic to research.');
        }
        $topic = mb_substr($topic, 0, 2000);

        $response = $this->post([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => "Research this topic and produce the brief:\n\n" . $topic],
            ],
            'temperature' => 0.2,
            'max_tokens' => 6000,
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || trim($content) === '') {
            throw new ResearchException('Researcher returned no content.');
        }

        return trim($content);
    }

    private function systemPrompt(): string
    {
        return "You are a research assistant with live web access. Produce a thorough, well-structured research brief in GitHub-flavored Markdown that a writer will use to draft an article. Ground every factual claim in current, reputable web sources.\n"
            . "Structure the brief with these sections (use ## headings):\n"
            . "- Overview — what the topic is and why it matters.\n"
            . "- Key facts & figures — concrete numbers, specs, dates, prices (with the source in parentheses).\n"
            . "- Notable products / brands / players — the main options and how they differ.\n"
            . "- Pros & cons / trade-offs.\n"
            . "- Common questions people ask (with concise answers).\n"
            . "- Angles for the article — 3-5 suggested directions or headlines.\n"
            . "- Sources — a bulleted list of the URLs you used.\n"
            . "Rules: never invent facts, figures, or sources; prefer primary/official sources; be specific over generic; if a fact is uncertain, say so. Output ONLY the markdown brief — no preamble.";
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
            throw new ResearchException('Research request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new ResearchException(sprintf('Research gateway returned HTTP %d: %s', $status, mb_substr((string) $raw, 0, 200)));
        }

        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new ResearchException('Research gateway returned a non-JSON response.');
        }

        return $decoded;
    }
}
