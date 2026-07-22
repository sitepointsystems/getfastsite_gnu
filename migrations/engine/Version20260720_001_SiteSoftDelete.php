<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Soft-delete for sites (UI-only trash). A `deleted_at` timestamp mirrors the
 * content trash pattern: normal reads filter `deleted_at IS NULL`; a soft-
 * deleted site drops out of the dashboard but keeps its DB rows + on-disk tree
 * so it can be recovered. A permanent purge (from the Trash view) removes the
 * row + `{DATA_PATH}/sites/{handle}/`. A column is used rather than a new
 * `status` value so the existing CHECK constraint is untouched.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE sites ADD COLUMN deleted_at TEXT');
        $db->executeStatement('CREATE INDEX ix_sites_deleted ON sites (deleted_at)');
    }
};
