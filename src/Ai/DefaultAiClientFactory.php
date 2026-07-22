<?php

declare(strict_types=1);

namespace Fastsite\Ai;

use Fastsite\FactCheck\ContentCorrector;
use Fastsite\FactCheck\FactChecker;
use Fastsite\FactCheck\NullFactChecker;
use Fastsite\FactCheck\PerplexityFactChecker;
use Fastsite\Research\NullResearcher;
use Fastsite\Research\PerplexityResearcher;
use Fastsite\Research\Researcher;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\EndpointrTranslator;
use Fastsite\Translation\NullTranslator;
use Fastsite\Translation\OpenRouterTranslator;
use Fastsite\Translation\Translator;

/**
 * Default routing (see {@see AiClientFactory}):
 *   translate = endpointr (.env, owner-paid) when configured, else the site's
 *               OpenRouter credential, else none;
 *   factcheck = the site's OpenRouter credential, else none.
 * The endpointr gateway is the owner's private-build service; the OpenRouter
 * credential spends the user's own tokens. class_exists guards let the OSS
 * build drop {@see EndpointrTranslator} (OpenRouter-only) without breaking DI.
 */
final class DefaultAiClientFactory implements AiClientFactory
{
    public function __construct(
        private readonly AiCredentialService $credentials,
        private readonly string $endpointrUrl,
        private readonly string $endpointrKey,
        private readonly string $defaultBaseUrl,
        private readonly string $defaultModel,
        private readonly string $defaultFactcheckModel,
        private readonly string $defaultResearchModel = 'perplexity/sonar',
        private readonly int $translateTimeout = 420,
    ) {
    }

    public function translatorForSite(Site $site): Translator
    {
        if ($this->endpointrConfigured() && class_exists(EndpointrTranslator::class)) {
            return new EndpointrTranslator($this->endpointrUrl, $this->endpointrKey, $this->translateTimeout);
        }

        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable() && class_exists(OpenRouterTranslator::class)) {
            return new OpenRouterTranslator(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->model !== '' ? $cred->model : $this->defaultModel,
                $this->translateTimeout,
            );
        }

        // endpointr present but its class was stripped from this build: still
        // translate via endpointr if OpenRouter is unavailable too.
        if ($this->endpointrConfigured() && class_exists(EndpointrTranslator::class)) {
            return new EndpointrTranslator($this->endpointrUrl, $this->endpointrKey, $this->translateTimeout);
        }

        return new NullTranslator();
    }

    public function factCheckerForSite(Site $site): FactChecker
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return new PerplexityFactChecker(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->factcheckModel !== '' ? $cred->factcheckModel : $this->defaultFactcheckModel,
            );
        }

        return new NullFactChecker();
    }

    public function correctorForSite(Site $site): ContentCorrector
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return new ContentCorrector(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->model !== '' ? $cred->model : $this->defaultModel,
            );
        }

        // Unconfigured — isConfigured() is false, so the corrector no-ops.
        return new ContentCorrector('', '', $this->defaultModel);
    }

    public function researcherForSite(Site $site): Researcher
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return new PerplexityResearcher(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->researchModel !== '' ? $cred->researchModel : $this->defaultResearchModel,
            );
        }

        return new NullResearcher();
    }

    public function translatorConfiguredForSite(Site $site): bool
    {
        return $this->translatorForSite($site)->isConfigured();
    }

    public function factCheckerConfiguredForSite(Site $site): bool
    {
        return $this->factCheckerForSite($site)->isConfigured();
    }

    public function researcherConfiguredForSite(Site $site): bool
    {
        return $this->researcherForSite($site)->isConfigured();
    }

    public function factCheckModelForSite(Site $site): string
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return $cred->factcheckModel !== '' ? $cred->factcheckModel : $this->defaultFactcheckModel;
        }

        return '';
    }

    private function endpointrConfigured(): bool
    {
        return $this->endpointrUrl !== '' && $this->endpointrKey !== '';
    }
}
