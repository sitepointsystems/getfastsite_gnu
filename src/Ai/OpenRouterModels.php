<?php

declare(strict_types=1);

namespace Fastsite\Ai;

use Predis\Client;

/**
 * The list of model ids available on OpenRouter, for the account AI-key model
 * pickers. Fetched from OpenRouter's public catalogue and cached in Redis for a
 * day; a curated fallback keeps the dropdowns usable if the fetch fails.
 */
final class OpenRouterModels
{
    private const CACHE_KEY = 'fastsite:openrouter:models';
    private const TTL = 86400;
    private const ENDPOINT = 'https://openrouter.ai/api/v1/models';

    /** Always offered even if the fetch fails, and always merged in. */
    private const FALLBACK = [
        'anthropic/claude-sonnet-latest', 'anthropic/claude-opus-latest', 'anthropic/claude-3.5-haiku',
        'openai/gpt-4o', 'openai/gpt-4o-mini', 'openai/o3-mini',
        'google/gemini-2.0-flash-001', 'meta-llama/llama-3.3-70b-instruct',
        'perplexity/sonar', 'perplexity/sonar-pro', 'perplexity/sonar-reasoning',
    ];

    public function __construct(private readonly Client $redis)
    {
    }

    /**
     * Sorted, de-duplicated model ids (fallbacks always included).
     *
     * @return list<string>
     */
    public function list(): array
    {
        $ids = $this->cached() ?? $this->fetch();
        $ids = array_values(array_unique([...$ids, ...self::FALLBACK]));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>|null
     */
    private function cached(): ?array
    {
        try {
            $raw = $this->redis->get(self::CACHE_KEY);
            if (\is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (\is_array($decoded) && $decoded !== []) {
                    return array_values(array_filter(array_map(static fn ($v): string => (string) $v, $decoded)));
                }
            }
        } catch (\Throwable) {
            // Redis down — fall through to a live fetch.
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function fetch(): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!\is_string($raw) || $status < 200 || $status >= 300) {
            return self::FALLBACK;
        }
        $decoded = json_decode($raw, true);
        $models = \is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $ids = array_values(array_filter(array_map(
            static fn ($m): string => \is_array($m) ? (string) ($m['id'] ?? '') : '',
            $models,
        ), static fn (string $s): bool => $s !== ''));
        if ($ids === []) {
            return self::FALLBACK;
        }

        try {
            $this->redis->setex(self::CACHE_KEY, self::TTL, json_encode($ids, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Caching is best-effort.
        }

        return $ids;
    }
}
