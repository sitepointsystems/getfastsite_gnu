<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-user GitHub credential vault (#1): fine-grained PATs stored encrypted
 * (TokenCipher), never in .env. A site selects which credential publishes it
 * via sites.github_credential_id (falls back to the global GITHUB_TOKEN).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE github_credentials (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                label TEXT NOT NULL,
                token_enc TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_used_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_github_credentials_user ON github_credentials (user_id)');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN github_credential_id INTEGER');
    }
};
