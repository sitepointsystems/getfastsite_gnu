<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE media (
                id INTEGER PRIMARY KEY,
                kind TEXT NOT NULL CHECK (kind IN ('image', 'video', 'file')),
                original_name TEXT NOT NULL,
                slug TEXT NOT NULL,
                ext TEXT NOT NULL,
                mime TEXT NOT NULL,
                sha256 TEXT NOT NULL,
                bytes INTEGER NOT NULL,
                width INTEGER,
                height INTEGER,
                alt TEXT NOT NULL DEFAULT '',
                decorative INTEGER NOT NULL DEFAULT 0,
                storage TEXT NOT NULL DEFAULT 'git' CHECK (storage IN ('git', 'r2')),
                disk_path TEXT NOT NULL,
                source_url TEXT,
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'ready', 'failed')),
                error TEXT,
                r2_synced_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE UNIQUE INDEX ux_media_sha ON media (sha256)');
        $db->executeStatement('CREATE INDEX ix_media_status ON media (status)');

        $db->executeStatement(<<<'SQL'
            CREATE TABLE media_variants (
                id INTEGER PRIMARY KEY,
                media_id INTEGER NOT NULL REFERENCES media (id) ON DELETE CASCADE,
                purpose TEXT NOT NULL CHECK (purpose IN ('responsive', 'og', 'poster')),
                format TEXT NOT NULL CHECK (format IN ('avif', 'webp', 'jpg', 'png')),
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                disk_path TEXT NOT NULL,
                bytes INTEGER NOT NULL,
                UNIQUE (media_id, purpose, format, width)
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_variants_media ON media_variants (media_id, purpose)');
    }
};
