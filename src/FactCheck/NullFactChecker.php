<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

/** No AI provider configured — fact-checking is unavailable. */
final class NullFactChecker implements FactChecker
{
    public function check(string $text, string $context = ''): FactCheckReport
    {
        throw new FactCheckException('Fact-checking is not configured. Set AI_API_KEY + AI_BASE_URL (an OpenRouter-compatible gateway) and optionally FACTCHECK_MODEL (default perplexity/sonar).');
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'disabled (no AI provider configured)';
    }
}
