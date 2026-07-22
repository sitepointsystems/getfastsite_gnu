<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

/**
 * Fact-checks content against the web. Implementations: PerplexityFactChecker
 * (real, web-grounded via the configured AI gateway) and NullFactChecker
 * (unconfigured). check() throws FactCheckException on any failure.
 */
interface FactChecker
{
    public function check(string $text, string $context = ''): FactCheckReport;

    public function isConfigured(): bool;

    /** Human label for doctor output. */
    public function describe(): string;
}
