<?php

declare(strict_types=1);

namespace Fastsite\Ai;

/**
 * A resolved (decrypted) AI gateway credential — the OpenRouter-compatible
 * key that pays for a user's own translation/fact-check tokens. Built by
 * {@see AiCredentialService::resolveForSite()}; never stored plaintext.
 */
final readonly class AiCredential
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public string $model,
        public string $factcheckModel,
        public string $researchModel = '',
        /**
         * Model for TRANSLATION specifically. Empty = use {@see $model}.
         * Separate because translation is the highest-volume call the engine
         * makes and the least in need of a frontier model — and because on a
         * per-account rate limit it is what parks the whole run.
         */
        public string $translateModel = '',
    ) {
    }

    /** The model translation should use: its own, else the general one. */
    public function translateModelOr(string $fallback): string
    {
        return $this->translateModel !== '' ? $this->translateModel : ($this->model !== '' ? $this->model : $fallback);
    }

    public function isUsable(): bool
    {
        return $this->apiKey !== '';
    }
}
