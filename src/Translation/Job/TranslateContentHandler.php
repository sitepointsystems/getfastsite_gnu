<?php

declare(strict_types=1);

namespace Fastsite\Translation\Job;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Translation\Service\TranslationService;
use Psr\Log\LoggerInterface;

/**
 * Brings a site's translations current, then chains a build+publish so the
 * newly translated locales appear. A heartbeat closure is threaded into the
 * sync so it ticks after every item — a large-site translation can take a
 * while, and without per-item heartbeats a healthy-but-slow run would be
 * falsely reclaimed as a dead worker.
 *
 * Runs are CHUNKED for fairness: one site's giant backlog must not hold the
 * translate worker for hours while every other site's translations wait.
 * Each run attempts at most the site's translate_chunk_size (content ×
 * locale) items, then re-enqueues itself at the BACK of the translate lane —
 * chunks from different sites interleave FIFO (every site gets its turn), and
 * builds/publishes run on their own lane so they are never behind a chunk.
 * Only the completing chunk translates the shell strings and chains
 * build+publish.
 *
 * The run takes the per-site lock. Nothing structurally prevented two workers
 * from picking up two chunks for the SAME site (the dedupe key only collapses
 * PENDING twins, so a chunk enqueued while another runs is a legitimate second
 * job) — they would then plan from the same rows and translate them twice,
 * paying for every call a second time. Lock-busy requeues without burning an
 * attempt, which is exactly right: the other chunk is already doing the work.
 */
final class TranslateContentHandler implements JobHandler
{
    /** Used when a site carries no explicit chunk size (older rows). */
    private const DEFAULT_ITEMS_PER_RUN = 10;

    /**
     * Rate-limited runs park the follow-up chunk with an escalating delay
     * (60s · 2^strikes, capped at 15 min) carried in payload.rl_strikes —
     * respecting the provider's limit instead of hammering it. Any run that
     * is NOT rate-limited clears the strikes.
     */
    private const RATE_LIMIT_BASE_DELAY_SECONDS = 60;
    private const RATE_LIMIT_MAX_DELAY_SECONDS = 900;

    public function __construct(
        private readonly TranslationService $translations,
        private readonly SiteRepository $sites,
        private readonly JobQueue $queue,
        private readonly SiteLock $lock,
        private readonly Clock $clock,
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

        $this->lock->withLock($handle, function () use ($site, $handle, $job, $context): void {
            $this->translate($site, $handle, $job, $context);
        });
    }

    private function translate(Site $site, string $handle, Job $job, JobContext $context): void
    {
        $done = 0;
        $heartbeat = static function () use ($context, &$done): void {
            $context->progress('Translating content — ' . ++$done . ' step(s) this chunk…');
        };
        $context->progress('Translating content…');
        $result = $this->translations->syncSite(
            $site,
            $heartbeat,
            $site->translateChunkSize > 0 ? $site->translateChunkSize : self::DEFAULT_ITEMS_PER_RUN,
        );
        $context->heartbeat();

        if (!$result['complete']) {
            $payload = $job->payload;

            if ($result['rate_limited']) {
                $strikes = min(4, max(0, (int) ($payload['rl_strikes'] ?? 0)));
                $delay = min(self::RATE_LIMIT_MAX_DELAY_SECONDS, self::RATE_LIMIT_BASE_DELAY_SECONDS * (2 ** $strikes));
                $payload['rl_strikes'] = $strikes + 1;
                $this->logger->warning('Translation rate-limited — parking the follow-up chunk', ['site' => $handle, 'delay' => $delay] + $result);
                $this->queue->enqueue(
                    'translate_content',
                    $handle,
                    $payload,
                    'low',
                    'translate:' . $handle,
                    runAfter: $this->clock->now()->add(new \DateInterval('PT' . $delay . 'S')),
                );

                return;
            }

            unset($payload['rl_strikes']); // a non-limited run resets the backoff
            $this->logger->info('Translation chunk done — more remains', ['site' => $handle] + $result);
            $this->queue->enqueue('translate_content', $handle, $payload, 'low', 'translate:' . $handle);

            return;
        }

        $context->progress('Translating shell strings…');
        $this->translations->syncShellStrings($site, static fn () => $context->heartbeat());
        $this->logger->info('Translation sync done', ['site' => $handle] + $result);

        // Rebuild so the translated pages ship (coalesced with any pending build).
        $message = (string) ($job->payload['message'] ?? 'publish: translations refreshed');
        $this->queue->enqueue(
            type: 'build_full',
            siteHandle: $handle,
            // Tail of a user-initiated publish chain — same reasoning as
            // PublishTrigger: re-render rather than trust the manifest.
            payload: ['trigger' => 'translate', 'force' => true],
            queue: 'high',
            dedupeKey: 'build:' . $handle,
            chain: \Fastsite\Build\Service\PublishTrigger::publishChain($site, $message),
        );
    }
}
