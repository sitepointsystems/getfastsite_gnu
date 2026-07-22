<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * User-managed header/footer scripts (analytics, chat widgets, pixels…).
 * Opt-in: a site with enabled scripts trades away the zero-JS / 100-PageSpeed
 * guarantee — the build relaxes that site's CSP + PageAudit accordingly.
 * location = where it's injected; strategy = how a src script loads.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE site_scripts (
                id INTEGER PRIMARY KEY,
                label TEXT NOT NULL DEFAULT '',
                location TEXT NOT NULL CHECK (location IN ('head', 'body_open', 'body_end')),
                strategy TEXT NOT NULL DEFAULT 'defer' CHECK (strategy IN ('blocking', 'async', 'defer')),
                priority INTEGER NOT NULL DEFAULT 100,
                code TEXT NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_site_scripts_order ON site_scripts (location, priority, id)');
    }
};
