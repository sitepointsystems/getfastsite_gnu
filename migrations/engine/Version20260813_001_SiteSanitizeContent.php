<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Site-wide content trust level. `sanitize_content = 0` makes VERBATIM the
 * default for every ingest path on the site (a trusted port whose bodies ship
 * their own scripts/styles/interactive markup) instead of forcing each write
 * to pass `sanitize:false`.
 *
 * The column also settles a long-standing asymmetry: the per-row flag only
 * ever governed STORAGE, so a verbatim body was re-sanitized on the way out
 * of translation and translated pages silently lost whatever the verbatim
 * mode existed to keep. Verbatim is now a property of the content, honoured
 * everywhere it is written.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN sanitize_content INTEGER NOT NULL DEFAULT 1',
        );
    }
};
