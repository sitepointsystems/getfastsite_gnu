<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Soft-delete for shell revisions.
 *
 * Deleting a snapshot has to free the zip without ever handing its revision
 * number to a later snapshot: snapshot() numbers with MAX(rev_no)+1, so a hard
 * DELETE of the newest revision would reissue that number and "restore rev 3"
 * would mean a different tree than it did an hour ago — including for the MCP
 * `shell_restore` tool, which takes the number as its only argument.
 *
 * Keeping a tombstone row holds the number permanently (rev_no is UNIQUE) while
 * the archive itself is unlinked, which is what actually costs disk. Mirrors the
 * media and site soft-delete columns.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE shell_revisions ADD COLUMN deleted_at TEXT');
    }
};
