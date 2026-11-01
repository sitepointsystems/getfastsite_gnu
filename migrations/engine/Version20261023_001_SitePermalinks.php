<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Category archives get a URL pattern and a page size.
 *
 * `post_path_pattern` has existed since the sites table was created, but
 * nothing could set it and it only knew `{slug}` — so every post lived under
 * `/blog/`. A site ported from WordPress keeps its posts at
 * `/{category}/{slug}/` and its archives at `/category/{slug}/`, and changing
 * those URLs means redirecting every inbound link, which is the one thing a
 * port is supposed to avoid. The post pattern itself needs no new column, only
 * a way to write it (SiteService::update) and a `{category}` token.
 *
 * Defaults are WordPress's own: `/category/{slug}/` and ten posts per archive
 * page. Existing sites get the columns but no archive pages until they create
 * a category, so their dist is byte-identical after this runs.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN category_path_pattern TEXT NOT NULL DEFAULT '/category/{slug}/'",
        );
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN archive_per_page INTEGER NOT NULL DEFAULT 10',
        );
    }
};
