<?php

declare(strict_types=1);

namespace Fastsite\Translation\Job;

use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Translation\Service\TranslationService;
use Psr\Log\LoggerInterface;

/**
 * Brings a site's translations current, then chains a build+publish so the
 * newly translated locales appear. A heartbeat closure is threaded into the
 * sync so it ticks after every item — a large-site translation can take a
 * while, and without per-item heartbeats a healthy-but-slow run would be
 * falsely reclaimed as a dead worker.
 */
final class TranslateContentHandler implements JobHandler
{
    public function __construct(
        private readonly TranslationService $translations,
        private readonly SiteRepository $sites,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'translate_content';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('translate_content needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        if ($site->locales === []) {
            return; // single-language site — nothing to translate
        }

        $heartbeat = static fn () => $context->heartbeat();
        $context->heartbeat();
        $result = $this->translations->syncSite($site, $heartbeat);
        $context->heartbeat();
        $this->translations->syncShellStrings($site, $heartbeat);
        $this->logger->info('Translation sync done', ['site' => $handle] + $result);

        // Rebuild so the translated pages ship (coalesced with any pending build).
        $message = (string) ($job->payload['message'] ?? 'publish: translations refreshed');
        $this->queue->enqueue(
            type: 'build_full',
            siteHandle: $handle,
            payload: ['trigger' => 'translate'],
            queue: 'high',
            dedupeKey: 'build:' . $handle,
            chain: [['type' => 'publish_git', 'payload' => ['message' => $message]]],
        );
    }
}
