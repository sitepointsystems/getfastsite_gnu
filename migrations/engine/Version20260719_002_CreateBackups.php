<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE backups (
                id INTEGER PRIMARY KEY,
                scope TEXT NOT NULL CHECK (scope IN ('engine', 'site')),
                site_handle TEXT,
                kind TEXT NOT NULL CHECK (kind IN ('db', 'media', 'shell', 'full')),
                storage_key TEXT NOT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                sha256 TEXT NOT NULL DEFAULT '',
                trigger_source TEXT NOT NULL DEFAULT 'schedule',
                status TEXT NOT NULL DEFAULT 'done' CHECK (status IN ('done', 'failed')),
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_backups_scope ON backups (scope, site_handle, kind, created_at)');
    }
};
