<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE api_keys (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                key_id TEXT NOT NULL UNIQUE,
                secret_hash TEXT NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(scopes)),
                sites TEXT NOT NULL DEFAULT '["*"]' CHECK (json_valid(sites)),
                last_used_at TEXT,
                last_used_ip TEXT,
                expires_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE idempotency_keys (
                key_hash TEXT PRIMARY KEY,
                endpoint TEXT NOT NULL,
                request_hash TEXT NOT NULL,
                response_status INTEGER NOT NULL,
                response_body TEXT NOT NULL,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY,
                actor_type TEXT NOT NULL CHECK (actor_type IN ('user', 'api_key', 'oauth', 'webhook', 'system')),
                user_id INTEGER,
                api_key_id INTEGER,
                site_handle TEXT,
                action TEXT NOT NULL,
                target TEXT NOT NULL DEFAULT '',
                detail TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(detail)),
                ip TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_audit_site ON audit_log (site_handle, created_at)');
    }
};
