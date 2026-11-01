<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Schema.org JSON-LD settings (structured data is ON by default — every site
 * gets a full entity graph on its next publish with no configuration).
 *
 * - structured_data: the kill switch, for a site that ships its own markup.
 * - schema_type:     what the site IS. A personal brand is a Person, not an
 *                    Organization, and Google treats those as different entity
 *                    classes rather than as a label.
 * - schema_same_as:  JSON list of profile URLs (LinkedIn, X, GitHub…). This is
 *                    the field that actually drives entity resolution; a name
 *                    and a URL on their own rarely do.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN structured_data INTEGER NOT NULL DEFAULT 1',
        );
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN schema_type TEXT NOT NULL DEFAULT 'Organization'",
        );
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN schema_same_as TEXT NOT NULL DEFAULT '[]'",
        );
    }
};
