<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * 404 capture (#15C): non-200 request paths recorded so the dashboard can
 * suggest one-click redirects instead of the operator hunting for broken
 * links. Populated by an edge collector (Cloudflare Worker / the 404 page
 * beacon) posting to the record endpoint.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE missing_paths (
                id INTEGER PRIMARY KEY,
                path TEXT NOT NULL UNIQUE,
                hits INTEGER NOT NULL DEFAULT 1,
                referrer TEXT NOT NULL DEFAULT '',
                resolved INTEGER NOT NULL DEFAULT 0,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_missing_unresolved ON missing_paths (resolved, hits)');
    }
};
