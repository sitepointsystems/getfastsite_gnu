<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE sites (
                id INTEGER PRIMARY KEY,
                handle TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                primary_domain TEXT NOT NULL DEFAULT '',
                base_url TEXT NOT NULL DEFAULT '',
                primary_locale TEXT NOT NULL DEFAULT 'en',
                locales TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(locales)),
                post_path_pattern TEXT NOT NULL DEFAULT '/blog/{slug}/',
                page_path_pattern TEXT NOT NULL DEFAULT '/{slug}/',
                github_repo TEXT NOT NULL DEFAULT '',
                github_branch TEXT NOT NULL DEFAULT 'main',
                github_token_enc TEXT,
                deploy_key_enc TEXT,
                cf_zone_id TEXT NOT NULL DEFAULT '',
                cf_pages_project TEXT NOT NULL DEFAULT '',
                media_public_base TEXT NOT NULL DEFAULT '',
                theme_version INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'archived')),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE site_users (
                site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role TEXT NOT NULL DEFAULT 'editor' CHECK (role IN ('owner', 'editor')),
                created_at TEXT NOT NULL,
                PRIMARY KEY (site_id, user_id)
            ) STRICT
            SQL);
    }
};
