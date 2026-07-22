<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Media soft-delete: a nullable deleted_at so the library (and files) can be
 * trashed (hidden from pickers, restorable) before an irreversible purge —
 * mirroring content's deleted_at. Referenced media still renders until purged.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE media ADD COLUMN deleted_at TEXT');
        $db->executeStatement('CREATE INDEX ix_media_deleted ON media (deleted_at)');
    }
};
