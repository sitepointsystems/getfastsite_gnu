<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Integrity manifest for the on-disk shell/ tree.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE shell_files (
                path TEXT PRIMARY KEY,
                sha256 TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE shell_revisions (
                id INTEGER PRIMARY KEY,
                rev_no INTEGER NOT NULL UNIQUE,
                archive_path TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                created_by TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            ) STRICT
            SQL);
    }
};
