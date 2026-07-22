<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL COLLATE NOCASE,
                name TEXT NOT NULL DEFAULT '',
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
                is_active INTEGER NOT NULL DEFAULT 1,
                totp_seed_enc TEXT,
                last_login_at TEXT,
                last_login_ip TEXT,
                failed_logins INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement(
            'CREATE UNIQUE INDEX ux_users_email ON users (email) WHERE deleted_at IS NULL',
        );
    }
};
