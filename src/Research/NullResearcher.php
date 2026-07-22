<?php

declare(strict_types=1);

namespace Fastsite\Research;

/** No AI credential configured — research is unavailable. */
final class NullResearcher implements Researcher
{
    public function research(string $topic): string
    {
        throw new ResearchException('Research is not configured. Add an OpenRouter key on your Account, pick a Research model, and select the credential on this site\'s Settings.');
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function modelName(): string
    {
        return '';
    }
}
