<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * New sites are LIVE by default, not in development.
 *
 * Version20260828_003 introduced `sites.development` with `DEFAULT 1` on the
 * reasoning that a site should be invisible until deliberately released. The
 * owner's call is the other way round, and it holds up: the asymmetry that
 * matters here is that forgetting to un-hide a site costs months of a launch
 * nobody can find, while a briefly-visible half-built page costs a day. Sites
 * that need hiding get it switched on per site, which is a decision someone is
 * present for — unlike the default, which nobody is.
 *
 * Done by rewriting the column's DEFAULT rather than by editing 003: that
 * migration is already released, and an edited already-applied migration
 * silently diverges between environments (the runner records the version and
 * never re-runs it, so whoever ran the old text keeps the old schema forever).
 *
 * SQLite cannot ALTER a column default, and the copy-and-swap rebuild is NOT
 * safe on `sites`: this runs with foreign_keys=ON, so DROP TABLE sites would
 * fire every child ON DELETE CASCADE. So: SQLite's documented writable_schema
 * procedure, which is legal precisely because a default does not affect the
 * on-disk row format. Unlike the other writable_schema migrations here, the
 * stored CREATE TABLE text is not retyped by hand — `sites` has accumulated
 * dozens of ALTER-added columns and transcribing them is how you lose one.
 * The current text is read back and ONE column definition is substituted, so
 * a mismatch is a no-op rather than a silently rewritten table.
 *
 * Existing rows are untouched: 003 already backfilled them to 0, and any site
 * deliberately switched to development since must stay hidden.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $sql = (string) $db->fetchOne(
            "SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'sites'",
        );

        $old = 'development INTEGER NOT NULL DEFAULT 1';
        $new = 'development INTEGER NOT NULL DEFAULT 0';

        // Not found = a fresh database whose 003 already wrote the new text, or
        // a shape this migration does not recognize. Either way, do nothing:
        // rewriting a table definition on a guess is how child tables die.
        if (!str_contains($sql, $old)) {
            return;
        }

        $db->executeStatement('PRAGMA writable_schema=ON');
        $db->executeStatement(
            "UPDATE sqlite_schema SET sql = ? WHERE type = 'table' AND name = 'sites'",
            [str_replace($old, $new, $sql)],
        );
        // Bump the schema cookie so every open connection reloads the text.
        $version = (int) $db->fetchOne('PRAGMA schema_version');
        $db->executeStatement('PRAGMA schema_version = ' . ($version + 1));
        $db->executeStatement('PRAGMA writable_schema=OFF');
    }
};
