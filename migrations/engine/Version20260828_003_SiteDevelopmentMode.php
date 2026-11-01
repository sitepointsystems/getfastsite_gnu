<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Development mode: while it is on, EVERY page the site publishes carries
 * `noindex, nofollow` (meta + X-Robots-Tag), so a half-built site can be
 * published, previewed and shared without ever reaching a search result.
 *
 * Its own column rather than a `sites.status` value: status is a lifecycle
 * label that gates nothing, and this is an output invariant the build has to
 * honour. Conflating them would mean one dropdown silently deciding whether a
 * customer's site is indexable.
 *
 * NEW sites default to development (DEFAULT 1) — an agent creating a site over
 * MCP must not be able to leak a half-written page into the index, and going
 * live should be a deliberate act. EXISTING sites are backfilled to 0: they are
 * already published and indexed, and flipping them to noindex on a migration
 * would be exactly the disaster this feature exists to prevent, in reverse.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN development INTEGER NOT NULL DEFAULT 1',
        );
        // ADD COLUMN … DEFAULT 1 stamps every existing row with 1. Put them
        // back to live — nothing that is already on the internet gets
        // de-indexed by an upgrade.
        $db->executeStatement('UPDATE sites SET development = 0');
    }
};
