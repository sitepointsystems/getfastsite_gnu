<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE components (
                id INTEGER PRIMARY KEY,
                handle TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL CHECK (type IN ('html', 'react')),
                source_code TEXT NOT NULL,
                css TEXT NOT NULL DEFAULT '',
                variables_schema TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(variables_schema)),
                source_hash TEXT NOT NULL DEFAULT '',
                bundle_hash TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'ready', 'failed')),
                warnings TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(warnings)),
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        // Locale-aware render cache: sha256(bundle_hash + values_json + locale).
        $db->executeStatement(<<<'SQL'
            CREATE TABLE component_renders (
                cache_key TEXT PRIMARY KEY,
                component_id INTEGER NOT NULL REFERENCES components (id) ON DELETE CASCADE,
                html TEXT NOT NULL,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);
    }
};
