<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE content (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL CHECK (type IN ('post', 'page')),
                slug TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft', 'scheduled', 'published', 'archived')),
                title TEXT NOT NULL,
                excerpt TEXT NOT NULL DEFAULT '',
                body_html TEXT NOT NULL DEFAULT '',
                featured_media_id INTEGER,
                author_user_id INTEGER,
                author_name TEXT NOT NULL DEFAULT '',
                source TEXT NOT NULL DEFAULT 'manual',
                source_ref TEXT,
                seo_title TEXT NOT NULL DEFAULT '',
                seo_description TEXT NOT NULL DEFAULT '',
                og_media_id INTEGER,
                json_ld TEXT CHECK (json_ld IS NULL OR json_valid(json_ld)),
                content_hash TEXT NOT NULL DEFAULT '',
                warnings TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(warnings)),
                scheduled_at TEXT,
                published_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement(
            'CREATE UNIQUE INDEX ux_content_type_slug ON content (type, slug) WHERE deleted_at IS NULL',
        );
        $db->executeStatement(
            'CREATE UNIQUE INDEX ux_content_source ON content (source, source_ref)
             WHERE source_ref IS NOT NULL AND deleted_at IS NULL',
        );
        $db->executeStatement(
            'CREATE INDEX ix_content_status ON content (status, published_at)',
        );

        $db->executeStatement(<<<'SQL'
            CREATE TABLE content_revisions (
                id INTEGER PRIMARY KEY,
                content_id INTEGER NOT NULL REFERENCES content (id) ON DELETE CASCADE,
                rev_no INTEGER NOT NULL,
                snapshot TEXT NOT NULL CHECK (json_valid(snapshot)),
                created_by TEXT NOT NULL DEFAULT '',
                reason TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                UNIQUE (content_id, rev_no)
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE redirects (
                id INTEGER PRIMARY KEY,
                from_path TEXT NOT NULL UNIQUE,
                to_path TEXT NOT NULL,
                status INTEGER NOT NULL DEFAULT 301,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE build_manifest (
                output_path TEXT PRIMARY KEY,
                source_hash TEXT NOT NULL DEFAULT '',
                rendered_sha256 TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);
    }
};
