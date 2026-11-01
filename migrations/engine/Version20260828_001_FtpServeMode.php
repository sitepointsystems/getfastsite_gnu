<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Third serve mode: `ftp` — every publish uploads the built tree to a directory
 * on the CUSTOMER'S OWN web host. Git and direct both make us the host; this one
 * does not. If the engine dies, the site stays up on hosting the customer owns.
 *
 * The target lives on the site row (each customer's host differs), following the
 * cf_api_token_enc / shortpixel_key_enc convention: non-secrets in plaintext so
 * the settings screen can show them without decrypting, secrets in *_enc.
 *
 * ftp_target_key is a hash of the whole target tuple. When it changes the site
 * is pointing somewhere new, so the remote manifest (site.db `ftp_manifest`)
 * describes a server we are no longer talking to and must be discarded —
 * otherwise the next publish would "delete orphans" on a host where those paths
 * were never ours.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // Widen the serve_mode CHECK. SQLite cannot ALTER a CHECK constraint,
        // and the copy-and-swap rebuild is unsafe here (foreign_keys=ON inside
        // the Migrator transaction — DROP TABLE sites would cascade). Use the
        // documented writable_schema procedure, as Version20260722_001_EditorRole
        // and Version20260812_003_JobLaneQueues do.
        //
        // Unlike those two we do NOT hand-copy the whole CREATE TABLE text:
        // `sites` has been ALTERed by ~15 migrations, so the stored text is a
        // moving target (it even carries the original heredoc's line break and
        // indentation inside the serve_mode column). Replace only the CHECK
        // clause, and fail loudly if it is not found — a silent no-op here would
        // let 'ftp' rows be written against a constraint that still rejects them.
        $current = $db->fetchOne("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'sites'");
        if (!\is_string($current) || $current === '') {
            throw new \RuntimeException('Cannot read the stored schema for `sites`.');
        }

        $old = "CHECK (serve_mode IN ('git', 'direct'))";
        $new = "CHECK (serve_mode IN ('git', 'direct', 'ftp'))";
        if (!str_contains($current, $old)) {
            // Already widened by a re-run, or the constraint text drifted.
            if (!str_contains($current, $new)) {
                throw new \RuntimeException('serve_mode CHECK clause not found in the stored `sites` schema — refusing to guess.');
            }
        } else {
            $db->executeStatement('PRAGMA writable_schema=ON');
            $db->executeStatement(
                "UPDATE sqlite_schema SET sql = ? WHERE type = 'table' AND name = 'sites'",
                [str_replace($old, $new, $current)],
            );
            $version = (int) $db->fetchOne('PRAGMA schema_version');
            $db->executeStatement('PRAGMA schema_version = ' . ($version + 1));
            $db->executeStatement('PRAGMA writable_schema=OFF');
        }

        // Port 0 = "use the protocol default" (21 for ftp/ftps, 22 for sftp), so
        // the common case needs no input and a stored 0 never means "port zero".
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN ftp_protocol TEXT NOT NULL DEFAULT 'sftp' CHECK (ftp_protocol IN ('ftp', 'ftps', 'sftp'))",
        );
        $db->executeStatement("ALTER TABLE sites ADD COLUMN ftp_host TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN ftp_port INTEGER NOT NULL DEFAULT 0');
        $db->executeStatement("ALTER TABLE sites ADD COLUMN ftp_username TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN ftp_password_enc TEXT');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN ftp_key_enc TEXT');
        $db->executeStatement("ALTER TABLE sites ADD COLUMN ftp_remote_path TEXT NOT NULL DEFAULT ''");
        $db->executeStatement("ALTER TABLE sites ADD COLUMN ftp_target_key TEXT NOT NULL DEFAULT ''");
    }
};
