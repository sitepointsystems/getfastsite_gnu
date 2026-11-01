<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Stores what PageAudit found on the last build, per site, so the Audit
 * screen (and REST, and MCP) can show it.
 *
 * This is the pipe that was missing. `BuildService::reportAudit()` handed
 * every finding to the logger and nowhere else, so a WARN landed in a file
 * inside the container and was never read by anyone — while the audit was
 * quietly detecting wrong canonical hosts, unsupported favicons, missing meta
 * descriptions and uncrawlable links on every build of every site. A quality
 * guard nobody can read is not a guard.
 *
 * Writes are wrapped, exactly as `TranslationLog` wraps its own: a site whose
 * migrations have not run yet, or a locked db, must never be able to fail a
 * build that rendered perfectly well. The cost of losing an audit row is one
 * missing screen; the cost of throwing here is a blocked publish.
 */
final class PageAuditLog
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Replaces the stored set wholesale.
     *
     * Findings describe ONE build, so they are not a log that accumulates: a
     * problem you have just fixed has to DISAPPEAR from the screen, not sit
     * there next to its own fix waiting to be misread as still broken.
     *
     * @param list<array{severity: string, page: string, message: string}> $findings
     */
    public function record(string $siteHandle, array $findings): void
    {
        try {
            $db = $this->databases->site($siteHandle);
            $at = $this->clock->iso();

            $db->executeStatement('DELETE FROM audit_findings');
            foreach ($findings as $finding) {
                $db->insert('audit_findings', [
                    // Anything that is not an explicit FAIL is stored as WARN:
                    // the column is CHECK-constrained, and a new severity
                    // string appearing upstream must not make the insert throw
                    // and cost us the whole audit.
                    'severity' => $finding['severity'] === 'FAIL' ? 'FAIL' : 'WARN',
                    'page' => $finding['page'],
                    'message' => $finding['message'],
                    'created_at' => $at,
                ]);
            }
        } catch (\Throwable) {
            // Bookkeeping must never fail a build that rendered fine.
        }
    }

    /**
     * The last build's findings, worst first.
     *
     * Ordering is FAIL before WARN, then by `page`. Site-level findings carry
     * `(site)` / `(css)` as their page, and `(` sorts below every letter in
     * ASCII, so the checks that apply to the whole site land at the top of the
     * list for free rather than buried among per-page rows.
     *
     * @return array{at: string, fails: int, warns: int, findings: list<array{severity: string, page: string, message: string}>}
     */
    public function latest(string $siteHandle): array
    {
        $empty = ['at' => '', 'fails' => 0, 'warns' => 0, 'findings' => []];

        try {
            $rows = $this->databases->site($siteHandle)->fetchAllAssociative(
                "SELECT severity, page, message, created_at FROM audit_findings
                 ORDER BY CASE severity WHEN 'FAIL' THEN 0 ELSE 1 END, page, id",
            );
        } catch (\Throwable) {
            // Not migrated yet — an empty audit, not an error page.
            return $empty;
        }

        if ($rows === []) {
            return $empty;
        }

        $findings = [];
        $fails = 0;
        foreach ($rows as $row) {
            $severity = (string) $row['severity'];
            if ($severity === 'FAIL') {
                ++$fails;
            }
            $findings[] = [
                'severity' => $severity,
                'page' => (string) $row['page'],
                'message' => (string) $row['message'],
            ];
        }

        return [
            'at' => (string) $rows[0]['created_at'],
            'fails' => $fails,
            'warns' => \count($findings) - $fails,
            'findings' => $findings,
        ];
    }
}
