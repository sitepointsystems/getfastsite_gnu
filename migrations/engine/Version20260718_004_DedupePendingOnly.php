<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Dedupe must only coalesce into jobs that have NOT started yet.
        // A running build has already snapshotted its input — merging a new
        // trigger into it silently drops the new work (review finding).
        $db->executeStatement('DROP INDEX IF EXISTS ux_jobs_dedupe');
        $db->executeStatement(
            "CREATE UNIQUE INDEX ux_jobs_dedupe ON jobs (dedupe_key)
             WHERE dedupe_key IS NOT NULL AND status = 'pending'",
        );
    }
};
