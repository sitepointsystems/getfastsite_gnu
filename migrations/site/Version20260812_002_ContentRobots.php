<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-content robots directives: noindex drops the page from search engines
 * (meta robots + excluded from sitemap.xml/llms.txt), nofollow asks crawlers
 * not to follow its links. Both default off — published content stays
 * indexable unless the author opts out.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE content ADD COLUMN noindex INTEGER NOT NULL DEFAULT 0 CHECK (noindex IN (0, 1))');
        $db->executeStatement('ALTER TABLE content ADD COLUMN nofollow INTEGER NOT NULL DEFAULT 0 CHECK (nofollow IN (0, 1))');
    }
};
