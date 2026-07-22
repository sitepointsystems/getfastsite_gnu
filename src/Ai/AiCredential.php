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
    ) {
    }

    public function isUsable(): bool
    {
        return $this->apiKey !== '';
    }
}
