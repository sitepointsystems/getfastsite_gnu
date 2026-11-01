<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site SEO title composition (the Site Settings "SEO" card).
 *
 * `seo_title_suffix` — whether the build appends the site name to every page
 * <title>/og:title ("My post — Site"). Default 1 = the behavior every site
 * shipped with; 0 drops the suffix. A content row's own seo_title always
 * bypasses the suffix either way.
 *
 * `seo_title_separator` — the character joining the title and the site name
 * (em dash default; allowlist in SiteService::TITLE_SEPARATORS).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE sites ADD COLUMN seo_title_suffix INTEGER NOT NULL DEFAULT 1');
        $db->executeStatement("ALTER TABLE sites ADD COLUMN seo_title_separator TEXT NOT NULL DEFAULT '—'");
    }
};
