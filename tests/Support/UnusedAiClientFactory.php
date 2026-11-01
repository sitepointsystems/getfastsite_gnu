<?php

declare(strict_types=1);

namespace Fastsite\Tests\Support;

use Fastsite\Ai\AiClientFactory;
use Fastsite\FactCheck\ContentCorrector;
use Fastsite\FactCheck\FactChecker;
use Fastsite\Media\AltTextGenerator;
use Fastsite\Research\Researcher;
use Fastsite\Seo\SeoRewriter;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\Translator;

/**
 * For tests that need a TranslationService (e.g. inside PublishTrigger) whose
 * AI clients must never actually be resolved — every method throws, so a code
 * path that unexpectedly reaches for a model fails the test loudly.
 */
final class UnusedAiClientFactory implements AiClientFactory
{
    public function translatorForSite(Site $site): Translator
    {
        throw new \RuntimeException('AI client requested in a test that must not translate.');
    }

    public function factCheckerForSite(Site $site): FactChecker
    {
        throw new \RuntimeException('AI client requested in a test that must not fact-check.');
    }

    public function correctorForSite(Site $site): ContentCorrector
    {
        throw new \RuntimeException('AI client requested in a test that must not correct.');
    }

    public function researcherForSite(Site $site): Researcher
    {
        throw new \RuntimeException('AI client requested in a test that must not research.');
    }

    public function altTextForSite(Site $site): AltTextGenerator
    {
        throw new \RuntimeException('AI client requested in a test that must not generate alt text.');
    }

    public function seoRewriterForSite(Site $site): SeoRewriter
    {
        throw new \RuntimeException('AI client requested in a test that must not rewrite SEO fields.');
    }

    public function translatorConfiguredForSite(Site $site): bool
    {
        return false;
    }

    public function factCheckerConfiguredForSite(Site $site): bool
    {
        return false;
    }

    public function researcherConfiguredForSite(Site $site): bool
    {
        return false;
    }

    public function factCheckModelForSite(Site $site): string
    {
        return '';
    }
}
