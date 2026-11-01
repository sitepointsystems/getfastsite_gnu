<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * When an image last went through the external optimizer (ShortPixel).
 * NULL = never attempted (or the attempt failed) — the /media screen offers an
 * "optimize" button only for those, so a site's credits are spent at most once
 * per image. Set on ingest when a key is configured, or by the retroactive
 * optimize action/job for images uploaded before the key existed.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE media ADD COLUMN optimized_at TEXT');
    }
};
