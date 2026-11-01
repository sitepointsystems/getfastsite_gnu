<?php

declare(strict_types=1);

namespace Fastsite\Media\Job;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Retroactively ShortPixel-optimizes a site's images ("Optimize all" on the
 * /media screen). payload.ids restricts the batch; absent = every ready image
 * without an optimized_at. Each image is marked as it completes, so a retried
 * or re-enqueued job never re-spends credits on finished items. One failing
 * image (API hiccup) is logged and skipped, not fatal — it stays unmarked and
 * a later run picks it up. Takes the site lock (rewrites originals + variants
 * a concurrent build would be copying).
 *
 * Runs are CHUNKED for fairness (translate_content pattern): each run
 * optimizes at most IMAGES_PER_RUN images, then re-enqueues itself at the
 * BACK of the media queue carrying the remaining ids and the cumulative
 * changed count — chunks from different sites interleave FIFO, no site can
 * hold the media worker (or its own 900s site lock) for a huge library. The
 * id list is resolved ONCE on the first run: re-querying per chunk would
 * re-pick permanently-failing (unmarked) images forever. Only the completing
 * chunk republishes, once, and the publish trigger fires AFTER the lock
 * releases so the queued build doesn't immediately bounce off it.
 */
final class OptimizeMediaHandler implements JobHandler
{
    /**
     * One ShortPixel round-trip per image → a chunk is minutes, far under
     * both the 900s site-lock TTL and the 600s stale-heartbeat cutoff.
     */
    private const IMAGES_PER_RUN = 25;

    public function __construct(
        private readonly MediaService $media,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
        private readonly PublishTrigger $publish,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'optimize_media';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('optimize_media needs a site.');
        $site = $this->sites->findByHandle($handle) ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $ids = $job->payload['ids'] ?? null;
        $ids = \is_array($ids) ? array_map(intval(...), array_values($ids)) : $this->media->unoptimizedImageIds($handle);
        $chunk = \array_slice($ids, 0, self::IMAGES_PER_RUN);
        $remaining = \array_slice($ids, self::IMAGES_PER_RUN);
        $changedBefore = (int) ($job->payload['changed'] ?? 0);

        $result = $this->lock->withLock($handle, function () use ($chunk, $site, $handle, $context): array {
            $changed = 0;
            $saved = 0;
            $failed = 0;
            $total = \count($chunk);
            foreach ($chunk as $i => $id) {
                $context->progress(sprintf('Optimizing image %d/%d…', $i + 1, $total));
                try {
                    $one = $this->media->optimizeExisting($site, $id);
                    if ($one['changed']) {
                        ++$changed;
                        $saved += $one['saved'];
                    }
                } catch (\Throwable $e) {
                    ++$failed;
                    $this->logger->warning('Optimize skipped an image', ['site' => $handle, 'media' => $id, 'error' => $e->getMessage()]);
                }
            }

            return ['changed' => $changed, 'saved' => $saved, 'failed' => $failed];
        });

        $changedTotal = $changedBefore + $result['changed'];
        $this->logger->info('Media optimization chunk finished', [
            'site' => $handle,
            'images' => \count($chunk),
            'remaining' => \count($remaining),
            'changed' => $result['changed'],
            'saved_bytes' => $result['saved'],
            'failed' => $result['failed'],
        ]);

        if ($remaining !== []) {
            $this->queue->enqueue(
                type: 'optimize_media',
                siteHandle: $handle,
                payload: ['ids' => $remaining, 'changed' => $changedTotal] + $job->payload,
                queue: 'media',
                dedupeKey: 'optimize:' . $handle,
            );

            return;
        }

        if ($changedTotal > 0) {
            $this->publish->trigger($site, 'publish: media optimized (' . $changedTotal . ' image(s))', 'media-optimize');
        }
    }
}
