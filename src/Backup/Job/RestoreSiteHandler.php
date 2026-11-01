<?php

declare(strict_types=1);

namespace Fastsite\Backup\Job;

use Fastsite\Backup\Service\BackupService;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;

/**
 * Restores a site from backup. payload.db_key / payload.media_key select a
 * specific snapshot (latest when omitted). Restoring the DB always enqueues
 * a rebuild+publish so the dist matches the restored rows. Under the site
 * lock so it never overlaps a build.
 */
final class RestoreSiteHandler implements JobHandler
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
    ) {
    }

    public static function type(): string
    {
        return 'restore_site';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('restore_site needs a site.');
        $site = $this->sites->findByHandle($handle) ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $this->lock->withLock($handle, function () use ($site, $job, $context): void {
            $context->heartbeat();
            if (($job->payload['media'] ?? false) === true || isset($job->payload['media_key'])) {
                $context->progress('Restoring media…');
                $this->backups->restoreSiteMedia($site, isset($job->payload['media_key']) ? (string) $job->payload['media_key'] : null);
            }
            if (($job->payload['shell'] ?? false) === true || isset($job->payload['shell_key'])) {
                $context->progress('Restoring shell…');
                $this->backups->restoreSiteShell($site, isset($job->payload['shell_key']) ? (string) $job->payload['shell_key'] : null);
            }
            // DB restore enqueues its own rebuild+publish chain.
            $context->progress('Restoring database…');
            $this->backups->restoreSiteDb($site, isset($job->payload['db_key']) ? (string) $job->payload['db_key'] : null);
        });
    }
}
