<?php

declare(strict_types=1);

namespace Fastsite\Build\Job;

use Fastsite\Build\Service\BuildService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;

final class BuildFullHandler implements JobHandler
{
    public function __construct(
        private readonly BuildService $build,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public static function type(): string
    {
        return 'build_full';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('build_full needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $this->lock->withLock($handle, function () use ($site, $job, $context): void {
            $db = $this->databases->engine();
            $db->insert('builds', [
                'site_handle' => $site->handle,
                'job_uuid' => $job->uuid,
                'trigger_source' => (string) ($job->payload['trigger'] ?? 'queue'),
                'scope' => 'full',
                'started_at' => $this->clock->iso(),
            ]);
            $buildId = (int) $db->lastInsertId();
            $context->progress('Building pages…');

            try {
                // A publish someone asked for re-renders everything; background
                // triggers stay incremental. PublishTrigger sets this.
                $force = (bool) ($job->payload['force'] ?? false);
                $stats = $this->build->build($site, static fn () => $context->heartbeat(), $force);
            } catch (\Throwable $e) {
                $db->update('builds', [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'finished_at' => $this->clock->iso(),
                ], ['id' => $buildId]);
                throw $e;
            }

            $db->update('builds', [
                'status' => 'done',
                'stats' => json_encode([
                    'pages' => $stats->pages,
                    'written' => $stats->written,
                    'skipped' => $stats->skipped,
                    'deleted' => $stats->deleted,
                    'bytes' => $stats->bytes,
                    'duration_ms' => $stats->durationMs,
                ], JSON_THROW_ON_ERROR),
                'finished_at' => $this->clock->iso(),
            ], ['id' => $buildId]);
        });
    }
}
