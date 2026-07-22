<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Web sessions (Redis is the live store; this table backs the
        // "active sessions" UI and lets OAuth authorize resolve the user).
        $db->executeStatement(<<<'SQL'
            CREATE TABLE user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                expires_at TEXT NOT NULL
            ) STRICT
            SQL);

        // OAuth clients — public (PKCE, no secret). Dynamically registered
        // (RFC 7591) or admin-created.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE oauth_clients (
                client_id TEXT PRIMARY KEY,
                client_name TEXT NOT NULL DEFAULT '',
                redirect_uris TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(redirect_uris)),
                logo_uri TEXT NOT NULL DEFAULT '',
                client_uri TEXT NOT NULL DEFAULT '',
                scope TEXT NOT NULL DEFAULT '',
                is_dynamic INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                last_used_at TEXT,
                revoked_at TEXT
            ) STRICT
            SQL);

        // Authorization codes — single-use, 60s, S256 PKCE.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE oauth_codes (
                code_hash TEXT PRIMARY KEY,
                client_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(scopes)),
                code_challenge TEXT NOT NULL,
                redirect_uri TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        // Access + refresh tokens — paired sha256 hashes per row, rotated on
        // refresh; reuse of a revoked refresh revokes the whole family.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE oauth_tokens (
                id INTEGER PRIMARY KEY,
                family_id TEXT NOT NULL,
                access_hash TEXT NOT NULL UNIQUE,
                refresh_hash TEXT UNIQUE,
                client_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(scopes)),
                access_expires_at TEXT NOT NULL,
                refresh_expires_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_oauth_tokens_family ON oauth_tokens (family_id)');

        // Consent memory — skip the consent screen on reconnect with the
        // same scope set.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE oauth_consents (
                user_id INTEGER NOT NULL,
                client_id TEXT NOT NULL,
                scope_set_hash TEXT NOT NULL,
                granted_at TEXT NOT NULL,
                PRIMARY KEY (user_id, client_id, scope_set_hash)
            ) STRICT
            SQL);
    }
};
