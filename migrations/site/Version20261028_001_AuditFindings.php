<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Where PageAudit's findings go, so they stop being write-only.
 *
 * The audit has run on every build since M4 and has always known exactly what
 * was wrong — a canonical pointing at the wrong host, a favicon Google cannot
 * read, a missing meta description, a 350 KB listing page. `reportAudit()`
 * handed every one of them to the logger, which writes to a FILE INSIDE THE
 * CONTAINER, and that was the end of it: nothing on the job row, nothing on
 * the dashboard, nothing over MCP or REST. A FAIL at least threw and blocked
 * the build, so somebody found out; every WARN beneath it died in silence,
 * which is how three live blog indexes sat over the byte budget for months.
 *
 * That is the same blindness that forced `translation_log` to exist, and this
 * is the same answer: a row per finding in the site's own db, replaced
 * wholesale by each build, read back by the Audit screen.
 *
 * No index: the table holds ONE build's findings (tens of rows at most) and
 * is always read in full.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE audit_findings (
                id INTEGER PRIMARY KEY,
                severity TEXT NOT NULL CHECK (severity IN ('FAIL', 'WARN')),
                page TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);
    }
};
