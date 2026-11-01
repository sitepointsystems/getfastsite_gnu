<?php

declare(strict_types=1);

namespace Fastsite\Ai;

use Fastsite\FactCheck\ContentCorrector;
use Fastsite\FactCheck\FactChecker;
use Fastsite\Research\Researcher;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\Translator;

/**
 * Resolves the right AI clients for a site at run time. Translation and
 * fact-checking use different gateways with different billing, so the choice
 * is per-site, not a boot-time singleton:
 *  - translation: the owner's endpointr gateway when configured (.env), else
 *    the site's own OpenRouter credential (user-paid);
 *  - fact-checking: always the site's own OpenRouter credential (user-paid).
 */
interface AiClientFactory
{
    public function translatorForSite(Site $site): Translator;

    public function factCheckerForSite(Site $site): FactChecker;

    /** Rewrite client for fact-check auto-correct (site's OpenRouter credential). */
    public function correctorForSite(Site $site): ContentCorrector;

    /** Topic-research client (site's OpenRouter credential + Research model). */
    public function researcherForSite(Site $site): Researcher;

    /** Vision alt-text writer (site's OpenRouter credential, general model). */
    public function altTextForSite(Site $site): \Fastsite\Media\AltTextGenerator;

    /** SEO title/description length rewriter (site's OpenRouter credential, general model). */
    public function seoRewriterForSite(Site $site): \Fastsite\Seo\SeoRewriter;

    public function translatorConfiguredForSite(Site $site): bool;

    public function factCheckerConfiguredForSite(Site $site): bool;

    public function researcherConfiguredForSite(Site $site): bool;

    /** The effective fact-check model for the site ('' when unconfigured) — for UI display. */
    public function factCheckModelForSite(Site $site): string;
}
