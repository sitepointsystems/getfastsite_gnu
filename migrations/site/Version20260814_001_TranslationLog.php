<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * A visible record of what the translation service actually sent and got back.
 *
 * Until now a translation failure left nothing a user could look at: the app
 * logger writes to a file inside the container, so a wedged translate job on
 * production was diagnosable only by shelling in. Every model call now lands
 * here — the field, the source excerpt, the RAW model answer, the timing, and
 * the exact provider error — and the Translations screen tails it live.
 *
 * Deliberately in the SITE db, not engine.db: it is per-site operational data
 * that should travel (and be pruned) with the site it belongs to.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'CREATE TABLE IF NOT EXISTS translation_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                job_uuid TEXT NOT NULL DEFAULT \'\',
                content_id INTEGER,
                title TEXT NOT NULL DEFAULT \'\',
                locale TEXT NOT NULL,
                field TEXT NOT NULL,
                format TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL CHECK (status IN (\'ok\', \'failed\', \'transport\', \'rate_limited\')),
                batched INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                request_chars INTEGER NOT NULL DEFAULT 0,
                response_chars INTEGER NOT NULL DEFAULT 0,
                request_excerpt TEXT NOT NULL DEFAULT \'\',
                response_excerpt TEXT NOT NULL DEFAULT \'\',
                error TEXT
            ) STRICT',
        );
        // The screen reads "newest first" and polls "anything after id N".
        $db->executeStatement('CREATE INDEX IF NOT EXISTS idx_translation_log_id ON translation_log (id DESC)');
    }
};
