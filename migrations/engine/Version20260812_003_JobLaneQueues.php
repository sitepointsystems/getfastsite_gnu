<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Widen jobs.queue CHECK with the dedicated lane queues (build /
        // translate / media) served by their own workers. SQLite cannot ALTER
        // a CHECK constraint; use the documented writable_schema procedure —
        // the on-disk row format is unchanged, only the constraint text
        // differs. The stored schema text at this point is the 20260812_002
        // shape PLUS `progress TEXT` appended last by that migration's ALTER
        // (SQLite rewrites the stored CREATE on ADD COLUMN) — the replacement
        // below must match that column list and order exactly. One-way: the
        // old CHECK would reject lane rows.
        $sql = <<<'SQL'
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY,
                uuid TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL,
                queue TEXT NOT NULL DEFAULT 'default' CHECK (queue IN ('high', 'default', 'low', 'build', 'translate', 'media')),
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
                created_at TEXT NOT NULL, progress TEXT
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
    }
};
