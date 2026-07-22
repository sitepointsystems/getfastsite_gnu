<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site branding: which media items are the favicon and the header/footer
 * logos (nullable — a site without them falls back to the shell defaults).
 * The header and footer logo are separate so a site can use different marks.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE sites ADD COLUMN favicon_media_id INTEGER');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN logo_header_media_id INTEGER');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN logo_footer_media_id INTEGER');
    }
};
