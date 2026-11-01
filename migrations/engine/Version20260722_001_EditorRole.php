<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Widen users.role CHECK to allow 'editor' (self-serve site creators:
        // everything except /admin). SQLite cannot ALTER a CHECK constraint,
        // and the usual copy-and-swap rebuild is NOT safe here: this runs with
        // foreign_keys=ON inside the Migrator's transaction, so DROP TABLE
        // users would fire every child ON DELETE CASCADE and wipe site_users,
        // teams, api_keys, oauth_* … Instead use SQLite's documented
        // writable_schema procedure for changes that don't affect the on-disk
        // row format: rewrite the stored CREATE TABLE text and bump the schema
        // cookie so all connections reload it. Column list/order/types must
        // match Version20260718_001_CreateUsers exactly.
        $sql = <<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL COLLATE NOCASE,
                name TEXT NOT NULL DEFAULT '',
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'editor', 'user')),
                is_active INTEGER NOT NULL DEFAULT 1,
                totp_seed_enc TEXT,
                last_login_at TEXT,
                last_login_ip TEXT,
                failed_logins INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT
            ) STRICT
            SQL;

        $db->executeStatement('PRAGMA writable_schema=ON');
        $db->executeStatement(
            "UPDATE sqlite_schema SET sql = ? WHERE type = 'table' AND name = 'users'",
            [$sql],
        );
        $version = (int) $db->fetchOne('PRAGMA schema_version');
        $db->executeStatement('PRAGMA schema_version = ' . ($version + 1));
        $db->executeStatement('PRAGMA writable_schema=OFF');
    }
};
