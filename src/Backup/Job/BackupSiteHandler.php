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
 * Backs up one site. payload.kind selects db | media | shell | full
 * (default full). Takes the site lock so a snapshot never races a build's
 * WAL checkpoint.
 */
final class BackupSiteHandler implements JobHandler
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
    ) {
    }

    public static function type(): string
    {
        return 'backup_site';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('backup_site needs a site.');
        $site = $this->sites->findByHandle($handle) ?? throw new \RuntimeException('Unknown site: ' . $handle);
        $kind = (string) ($job->payload['kind'] ?? 'full');
        $source = (string) ($job->payload['trigger'] ?? 'schedule');

        $this->lock->withLock($handle, function () use ($site, $kind, $source, $context): void {
            $context->heartbeat();
            match ($kind) {
                'db' => $this->backups->backupSiteDb($site, $source),
                'media' => $this->backups->backupSiteMedia($site, $source),
                'shell' => $this->backups->backupSiteShell($site, $source),
                'full' => $this->backups->backupSite($site, $source),
                default => throw new \RuntimeException('Unknown backup kind: ' . $kind),
            };
            $context->heartbeat();
        });
    }
}
