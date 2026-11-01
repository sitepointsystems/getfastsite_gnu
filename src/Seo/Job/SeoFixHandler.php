<?php

declare(strict_types=1);

namespace Fastsite\Seo\Job;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Seo\SeoLengthService;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Rewrites a site's over/under-length SEO titles + descriptions to the
 * Google/Yoast envelope (SeoLengthService::fix), then republishes when
 * anything changed — via PublishTrigger, because the rewrites bump each
 * row's content_hash: on a multilingual site the meta translations just
 * went stale, and a direct build would ship the OLD translated titles and
 * skip the translate step entirely. payload.id narrows the run to one
 * content row. Under SiteLock (writes content rows); heartbeats between
 * items so a long AI run is never reclaimed as dead. A configured-but-erroring
 * AI FAILS the job (after publishing whatever did get fixed) so the reason
 * lands on the job row and the queue retries — never a silent no-op "done".
 */
final class SeoFixHandler implements JobHandler
{
    public function __construct(
        private readonly SeoLengthService $seo,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
        private readonly PublishTrigger $publish,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'seo_fix';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('seo_fix needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);
        $onlyId = isset($job->payload['id']) && (int) $job->payload['id'] > 0 ? (int) $job->payload['id'] : null;

        $result = $this->lock->withLock($handle, function () use ($site, $onlyId, $context): array {
            $context->progress('Rewriting SEO titles/descriptions…');

            return $this->seo->fix($site, $onlyId, static fn () => $context->heartbeat());
        });

        $this->logger->info('SEO length fix done', ['site' => $handle, 'fixed' => $result['fixed'], 'skipped' => $result['skipped'], 'failed' => $result['failed'], 'ai' => $result['ai']]);

        // Publish BEFORE failing on AI errors — rows that were fixed must
        // ship even when others still need a retry.
        if ($result['fixed'] > 0) {
            $this->publish->trigger($site, 'publish: SEO titles/descriptions fixed to snippet limits', 'seo-fix');
        }

        // A configured AI that errored is a retryable failure, and it must be
        // VISIBLE: throwing keeps the reason on the job row (dashboards and
        // list_jobs show it) instead of the run ending "done" having silently
        // changed nothing. Already-fixed rows fall out of the flagged set, so
        // the retry only revisits the failures.
        if ($result['failed'] > 0) {
            throw new \RuntimeException(sprintf(
                'SEO fix: AI rewrite failed for %d item(s) — %s (%d fixed, %d skipped this run)',
                $result['failed'],
                $this->firstFailure($result['items']),
                $result['fixed'],
                $result['skipped'],
            ));
        }
    }

    /**
     * @param list<array{id: int, slug: string, result: string}> $items
     */
    private function firstFailure(array $items): string
    {
        foreach ($items as $item) {
            if (str_starts_with($item['result'], 'failed')) {
                return $item['result'];
            }
        }

        return 'unknown error';
    }
}
