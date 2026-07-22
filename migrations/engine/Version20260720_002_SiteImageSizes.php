<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site image-size preferences (configurable in Site settings):
 *  - image_max_width: the largest responsive variant generated/served, so a
 *    4000px upload no longer ships a 4000px asset (default 2048).
 *  - logo_max_width: the target width used when serving the header/footer logo
 *    (the build picks the smallest variant ≥ this), so a huge logo isn't served
 *    at full resolution (default 400).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE sites ADD COLUMN image_max_width INTEGER NOT NULL DEFAULT 2048');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN logo_max_width INTEGER NOT NULL DEFAULT 400');
    }
};
