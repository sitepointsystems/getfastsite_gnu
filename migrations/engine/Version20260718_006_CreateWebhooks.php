<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE webhook_integrations (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                site_handle TEXT NOT NULL,
                mapper TEXT NOT NULL,
                secret_enc TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE webhook_deliveries (
                id INTEGER PRIMARY KEY,
                integration_id INTEGER NOT NULL REFERENCES webhook_integrations (id) ON DELETE CASCADE,
                delivery_uuid TEXT NOT NULL,
                event TEXT NOT NULL DEFAULT '',
                headers_json TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(headers_json)),
                raw_body TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'received'
                    CHECK (status IN ('received', 'processed', 'skipped', 'failed')),
                result TEXT NOT NULL DEFAULT '',
                error TEXT,
                created_at TEXT NOT NULL,
                processed_at TEXT
            ) STRICT
            SQL);

        // Replay guard: a delivery id is unique per integration.
        $db->executeStatement(
            'CREATE UNIQUE INDEX ux_deliveries_uuid ON webhook_deliveries (integration_id, delivery_uuid)',
        );
        $db->executeStatement('CREATE INDEX ix_deliveries_status ON webhook_deliveries (status, created_at)');
    }
};
