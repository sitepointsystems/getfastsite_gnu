<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-user AI (OpenRouter) credential vault. The key pays for the USER's own
 * tokens (translation fallback + fact-checking), so it is never a platform
 * .env secret — it is stored TokenCipher-encrypted per account, exactly like
 * github_credentials. A site selects which credential it uses via
 * sites.ai_credential_id; a translate/fact-check job resolves it at run time.
 * The owner's endpointr gateway (ENDPOINTR_*) is a separate, owner-paid path
 * that stays in .env and is used for translation when configured.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE ai_credentials (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                label TEXT NOT NULL,
                key_enc TEXT NOT NULL,
                base_url TEXT NOT NULL DEFAULT '',
                model TEXT NOT NULL DEFAULT '',
                factcheck_model TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                last_used_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_ai_credentials_user ON ai_credentials (user_id)');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN ai_credential_id INTEGER');
    }
};
