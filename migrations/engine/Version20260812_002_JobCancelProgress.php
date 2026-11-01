<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Widen jobs.status CHECK to allow 'cancelled' (operator-initiated
        // terminal state, /admin/ops). SQLite cannot ALTER a CHECK constraint;
        // use the documented writable_schema procedure (EditorRole pattern) —
        // the on-disk row format is unchanged, only the constraint text
        // differs. Column list/order/types must match
        // Version20260718_003_CreateJobs exactly.
        $sql = <<<'SQL'
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY,
                uuid TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL,
                queue TEXT NOT NULL DEFAULT 'default' CHECK (queue IN ('high', 'default', 'low')),
                site_handle TEXT,
                payload TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(payload)),
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'running', 'done', 'failed', 'dead', 'cancelled')),
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
            SQL;

        $db->executeStatement('PRAGMA writable_schema=ON');
        $db->executeStatement(
            "UPDATE sqlite_schema SET sql = ? WHERE type = 'table' AND name = 'jobs'",
            [$sql],
        );
        $version = (int) $db->fetchOne('PRAGMA schema_version');
        $db->executeStatement('PRAGMA schema_version = ' . ($version + 1));
        $db->executeStatement('PRAGMA writable_schema=OFF');

        // Free-text phase note the running handler updates between phases
        // ("Optimizing image 12/87…") — shown live on /admin/ops. Added AFTER
        // the rewrite so the stored CREATE TABLE text above stays the exact
        // 003 shape.
        $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress TEXT');
    }
};
