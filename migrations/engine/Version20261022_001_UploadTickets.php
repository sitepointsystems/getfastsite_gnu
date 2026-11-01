<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Upload tickets: a short-lived, single-use, scoped URL that accepts raw bytes.
 *
 * The problem it solves is not a size limit in the service — it is that over
 * MCP the bytes travel as an ARGUMENT of the tool call, so the model has to
 * emit them token by token at about 4/3 the size of the file. That exhausts the
 * output budget around 100 KB, four orders of magnitude below the 15 MB the
 * media pipeline accepts, and it fails by TRUNCATING rather than erroring: the
 * row still reports `status: ready` while holding a grey rectangle.
 *
 * A ticket moves the bytes off the model's output path entirely. Whoever holds
 * the file does one HTTP PUT; the engine ingests through the same pipeline a
 * multipart upload uses, so crop, variants, ShortPixel and auto-alt all apply.
 *
 * Why this and not an FTP drop into a public folder (the first idea): nothing
 * can speak FTP but not HTTPS, so FTP unlocks no caller that a PUT does not —
 * and a standing FTP credential cannot carry a site or a scope, so it would be
 * one shared secret across every customer's site, which is the opposite of the
 * per-site authz every other transport here goes through. A ticket is minted BY
 * that authz and inherits it: the site and the actor are fixed at mint time and
 * the holder of the URL can do exactly one thing with it.
 *
 * In engine.db rather than a site db on purpose: the token has to be resolvable
 * before the site is known, or the URL would have to carry the site handle and
 * the lookup would happen after a path-derived site resolution instead of
 * before it.
 *
 * `token_hash` only — never the token. Same rule as oauth_tokens and API key
 * secrets: a leaked engine.db must not hand anyone a usable credential.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE upload_tickets (
                id INTEGER PRIMARY KEY,
                token_hash TEXT NOT NULL UNIQUE,
                site_handle TEXT NOT NULL,
                actor_type TEXT NOT NULL,
                actor_id INTEGER,
                filename TEXT NOT NULL DEFAULT '',
                alt TEXT NOT NULL DEFAULT '',
                decorative INTEGER NOT NULL DEFAULT 0,
                max_bytes INTEGER NOT NULL,
                expires_at TEXT NOT NULL,
                claimed_at TEXT,
                media_id INTEGER,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        // Pruning reads by expiry (done opportunistically on every mint, so no
        // new scheduler job carries this).
        $db->executeStatement('CREATE INDEX ix_upload_tickets_expires ON upload_tickets (expires_at)');
    }
};
