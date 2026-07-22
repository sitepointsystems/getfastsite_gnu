<?php

declare(strict_types=1);

namespace Fastsite\Backup\Job;

use Fastsite\Backup\Service\BackupService;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;

final class PruneBackupsHandler implements JobHandler
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    public static function type(): string
    {
        return 'prune_backups';
    }

    public function run(Job $job, JobContext $context): void
    {
        $context->heartbeat();
        $this->backups->prune();
    }
}
