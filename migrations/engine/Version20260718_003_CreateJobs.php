<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY,
                uuid TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL,
                queue TEXT NOT NULL DEFAULT 'default' CHECK (queue IN ('high', 'default', 'low')),
                site_handle TEXT,
                payload TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(payload)),
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'running', 'done', 'failed', 'dead')),
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 5,
                dedupe_key TEXT,
                chain TEXT CHECK (chain IS NULL OR json_valid(chain)),
                run_after TEXT,
                reserved_by TEXT,
                heartbeat_at TEXT,
                started_at TEXT,
                finished_at TEXT,
                error TEXT,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(
            "CREATE UNIQUE INDEX ux_jobs_dedupe ON jobs (dedupe_key)
             WHERE dedupe_key IS NOT NULL AND status IN ('pending', 'running')",
        );
        $db->executeStatement('CREATE INDEX ix_jobs_status_heartbeat ON jobs (status, heartbeat_at)');
        $db->executeStatement('CREATE INDEX ix_jobs_site_status ON jobs (site_handle, status)');

        $db->executeStatement(<<<'SQL'
            CREATE TABLE builds (
                id INTEGER PRIMARY KEY,
                site_handle TEXT NOT NULL,
                job_uuid TEXT NOT NULL DEFAULT '',
                trigger_source TEXT NOT NULL DEFAULT 'manual',
                scope TEXT NOT NULL DEFAULT 'full' CHECK (scope IN ('full', 'incremental')),
                status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'done', 'failed')),
                commit_sha TEXT NOT NULL DEFAULT '',
                stats TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(stats)),
                error TEXT,
                started_at TEXT NOT NULL,
                finished_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_builds_site ON builds (site_handle, status, finished_at)');
    }
};
