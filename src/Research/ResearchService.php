<?php

declare(strict_types=1);

namespace Fastsite\Research;

use Fastsite\Ai\AiClientFactory;
use Fastsite\Site\Dto\Site;

/**
 * Topic research for content writing. An agent (via MCP/REST) asks to research
 * a topic BEFORE drafting an article; the returned markdown brief is the
 * source material it writes from. Uses the site's own OpenRouter credential and
 * its Research model (default perplexity/sonar) — resolved per site, like
 * fact-checking. Stateless: nothing is persisted.
 */
final class ResearchService
{
    public function __construct(private readonly AiClientFactory $ai)
    {
    }

    public function isConfigured(Site $site): bool
    {
        return $this->ai->researcherConfiguredForSite($site);
    }

    /**
     * Research a topic and return the markdown brief + the model used.
     *
     * @return array{topic: string, model: string, markdown: string}
     */
    public function research(Site $site, string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            throw new ResearchException('A topic is required.');
        }

        $researcher = $this->ai->researcherForSite($site);
        $markdown = $researcher->research($topic);

        return [
            'topic' => $topic,
            'model' => $researcher->modelName(),
            'markdown' => $markdown,
        ];
    }
}
