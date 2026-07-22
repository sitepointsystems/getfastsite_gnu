<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE content_translations (
                id INTEGER PRIMARY KEY,
                content_id INTEGER NOT NULL REFERENCES content (id) ON DELETE CASCADE,
                locale TEXT NOT NULL,
                slug TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                excerpt TEXT NOT NULL DEFAULT '',
                body_html TEXT NOT NULL DEFAULT '',
                seo_title TEXT NOT NULL DEFAULT '',
                seo_description TEXT NOT NULL DEFAULT '',
                -- the content_hash of the SOURCE row this translation was made
                -- from; when it differs from content.content_hash the row is
                -- stale and gets re-translated.
                source_hash TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'translated', 'failed', 'manual')),
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (content_id, locale),
                UNIQUE (locale, slug)
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_ctrans_status ON content_translations (status)');

        // Short shell/UI/tag strings, translated per-key with per-key
        // staleness (source_hash of the source string).
        $db->executeStatement(<<<'SQL'
            CREATE TABLE string_translations (
                id INTEGER PRIMARY KEY,
                scope TEXT NOT NULL,
                ref TEXT NOT NULL,
                locale TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                value TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'translated'
                    CHECK (status IN ('translated', 'failed', 'manual')),
                updated_at TEXT NOT NULL,
                UNIQUE (scope, ref, locale)
            ) STRICT
            SQL);
    }
};
