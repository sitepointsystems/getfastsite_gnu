<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Structured post fields: intro (lead paragraph), tldr (summary box) and
 * conclusion (closing section) — HTML fragments the shell renders as styled
 * boxes so a site's post design lives in ONE template instead of being baked
 * into every body_html. NULL = not provided (ContentService normalizes ''
 * to NULL so the conditional content_hash rule stays unambiguous).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE content ADD COLUMN intro TEXT');
        $db->executeStatement('ALTER TABLE content ADD COLUMN tldr TEXT');
        $db->executeStatement('ALTER TABLE content ADD COLUMN conclusion TEXT');
        $db->executeStatement('ALTER TABLE content_translations ADD COLUMN intro TEXT');
        $db->executeStatement('ALTER TABLE content_translations ADD COLUMN tldr TEXT');
        $db->executeStatement('ALTER TABLE content_translations ADD COLUMN conclusion TEXT');
    }
};
