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
 *   translate = the site's OpenRouter credential when one is SELECTED, else
 *               endpointr (.env, owner-paid), else none;
 *   factcheck = the site's OpenRouter credential, else none;
 *   seo rewrite = the site's OpenRouter credential (user-paid first), else
 *               endpointr, else none.
 * The endpointr gateway is the owner's private-build service; the OpenRouter
 * credential spends the user's own tokens. class_exists guards let the OSS
 * build drop {@see EndpointrTranslator} (OpenRouter-only) without breaking DI.
 *
 * TRANSLATION PRECEDENCE IS DELIBERATE AND WAS THE OTHER WAY ROUND. endpointr
 * used to win whenever it was configured, so picking an AI key on Site
 * Settings changed fact-checking and SEO but silently did NOTHING for
 * translation: a rate-limited gateway kept being used, and the only visible
 * symptom was a translate job parking every minute forever. Selecting a
 * credential is an explicit instruction — `resolveForSite` returns null unless
 * `sites.ai_credential_id` is set — so honouring it can never move a site onto
 * user-paid tokens by accident; a site with nothing selected still uses the
 * owner's gateway exactly as before.
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
        /**
         * Fallback when a key names no translation model of its own. NOT the
         * general default model: translation is the highest-volume call the
         * engine makes and the least in need of a frontier model, so the
         * out-of-the-box choice is a fast cheap one rather than Sonnet.
         */
        private readonly string $defaultTranslateModel = 'google/gemini-2.5-flash-lite',
    ) {
    }

    public function translatorForSite(Site $site): Translator
    {
        // An explicitly selected key wins — see the class docblock.
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable() && class_exists(OpenRouterTranslator::class)) {
            return new OpenRouterTranslator(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->translateModelOr($this->defaultTranslateModel),
                $this->translateTimeout,
            );
        }

        if ($this->endpointrConfigured() && class_exists(EndpointrTranslator::class)) {
            return new EndpointrTranslator($this->endpointrUrl, $this->endpointrKey, $this->translateTimeout);
        }

        return new NullTranslator();
    }

    /**
     * Which translator a site will actually use, and with which model — so the
     * Translations screen can SAY so. Selecting an AI key and seeing no change
     * was the whole confusion; the answer belongs on screen, not in a docblock.
     *
     * `problem` is non-empty when the site HAS selected a key that could not be
     * used. Falling back to the gateway silently in that case is what made this
     * class of bug so hard to see: the user picks a key, nothing changes, and
     * the screen cheerfully reports the gateway as if that were the intent.
     *
     * @return array{provider: string, model: string, paid_by: string, problem: string}
     */
    public function translatorDescriptionForSite(Site $site): array
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable() && class_exists(OpenRouterTranslator::class)) {
            return [
                'provider' => 'OpenRouter',
                'model' => $cred->translateModelOr($this->defaultTranslateModel),
                'paid_by' => 'this site\'s AI key',
                'problem' => '',
            ];
        }

        $problem = '';
        if ($site->aiCredentialId !== null) {
            $problem = $cred === null || !$cred->isUsable()
                ? 'This site has an AI key selected, but it could not be read — the key was deleted, or it cannot be decrypted (APP_SECRET changed since it was saved). Re-save the key on Account, then re-select it here.'
                : 'This site has an AI key selected, but this build cannot use OpenRouter for translation. Redeploy from a build that ships the OpenRouter translator.';
        }

        if ($this->endpointrConfigured() && class_exists(EndpointrTranslator::class)) {
            return ['provider' => 'Built-in AI gateway', 'model' => 'gateway default', 'paid_by' => 'the engine owner', 'problem' => $problem];
        }

        return ['provider' => '', 'model' => '', 'paid_by' => '', 'problem' => $problem];
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

    public function altTextForSite(Site $site): \Fastsite\Media\AltTextGenerator
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return new \Fastsite\Media\AltTextGenerator(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->model !== '' ? $cred->model : $this->defaultModel,
            );
        }

        // Unconfigured — isConfigured() is false, generate() returns null.
        return new \Fastsite\Media\AltTextGenerator('', '', $this->defaultModel);
    }

    public function seoRewriterForSite(Site $site): \Fastsite\Seo\SeoRewriter
    {
        $cred = $this->credentials->resolveForSite($site);
        if ($cred !== null && $cred->isUsable()) {
            return new \Fastsite\Seo\SeoRewriter(
                $cred->baseUrl !== '' ? $cred->baseUrl : $this->defaultBaseUrl,
                $cred->apiKey,
                $cred->model !== '' ? $cred->model : $this->defaultModel,
            );
        }

        // No per-site OpenRouter credential — fall back to the owner's
        // endpointr gateway, like translation does. Without this, a private
        // deployment whose AI runs entirely through endpointr got an
        // UNCONFIGURED rewriter: rewrite() returned null in zero seconds and
        // every too-short title/description was silently skipped ("Fix with
        // AI did nothing"). The gateway client adapts the rewriter's
        // OpenAI-style chat body onto endpointr's {system, prompt} API.
        if ($this->endpointrConfigured() && class_exists(EndpointrChatClient::class)) {
            $client = new EndpointrChatClient($this->endpointrUrl, $this->endpointrKey);

            return new \Fastsite\Seo\SeoRewriter('', '', '', 60, $client->seoRewriterTransport());
        }

        // Unconfigured — isConfigured() is false, rewrite() returns null.
        return new \Fastsite\Seo\SeoRewriter('', '', $this->defaultModel);
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
