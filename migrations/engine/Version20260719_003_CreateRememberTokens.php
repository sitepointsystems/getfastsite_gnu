<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Remember-me: cookie = "{selector}.{validator}". The selector is
        // the lookup key; only sha256(validator) is stored, so a DB leak
        // cannot mint valid cookies. Tokens rotate on every use.
        $db->executeStatement(<<<'SQL'
            CREATE TABLE remember_tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_remember_user ON remember_tokens (user_id)');
    }
};
