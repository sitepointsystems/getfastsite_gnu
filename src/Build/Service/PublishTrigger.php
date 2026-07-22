<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;

/**
 * The one place that decides how a site gets rebuilt+published. On a
 * multilingual site it routes through translate_content (refresh
 * translations → build → publish); otherwise straight to build → publish.
 * Every content-changing trigger (API publish, webhook, shell edit,
 * scheduler) uses this so none can accidentally skip translation.
 */
final class PublishTrigger
{
    public function __construct(private readonly JobQueue $queue)
    {
    }

    public function trigger(Site $site, string $message, string $source): string
    {
        if ($site->locales !== []) {
            return $this->queue->enqueue(
                type: 'translate_content',
                siteHandle: $site->handle,
                payload: ['trigger' => $source, 'message' => $message],
                queue: 'default',
                dedupeKey: 'translate:' . $site->handle,
            );
        }

        return $this->queue->enqueue(
            type: 'build_full',
            siteHandle: $site->handle,
            payload: ['trigger' => $source],
            queue: 'high',
            dedupeKey: 'build:' . $site->handle,
            chain: [['type' => 'publish_git', 'payload' => ['message' => $message]]],
        );
    }
}
