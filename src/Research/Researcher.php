<?php

declare(strict_types=1);

namespace Fastsite\Research;

/**
 * Researches a topic against the web and returns a markdown research brief a
 * writer (human or agent) can draft an article from. Implementations:
 * PerplexityResearcher (real, web-grounded via the site's OpenRouter
 * credential) and NullResearcher (unconfigured). research() throws
 * ResearchException on any failure.
 */
interface Researcher
{
    /** @return string markdown research brief */
    public function research(string $topic): string;

    public function isConfigured(): bool;

    /** The model id in use (for the response payload / doctor output). */
    public function modelName(): string;
}
