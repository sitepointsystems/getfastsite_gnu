<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Backup\Service\BackupService;
use Fastsite\Dashboard\Dash;
use Fastsite\Ops\DoctorService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /admin/ops — screen 8: jobs (tabbed: running/pending default, all,
 * done, failed), build history, backups, doctor (doctor runs only on
 * ?doctor=1 — it probes git remotes and takes seconds).
 */
final class OpsScreenHandler implements RequestHandlerInterface
{
    private const TABS = ['active', 'all', 'done', 'failed'];

    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
        private readonly BackupService $backups,
        private readonly DoctorService $doctor,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $engine = $this->databases->engine();
        $query = $request->getQueryParams();

        $tab = (string) ($query['jobs'] ?? 'active');
        if (!\in_array($tab, self::TABS, true)) {
            $tab = 'active';
        }

        $where = match ($tab) {
            'active' => "status IN ('pending', 'running')",
            'done' => "status = 'done'",
            'failed' => "status IN ('failed', 'dead', 'cancelled')",
            default => '1=1',
        };
        // Active tab: running first, then pending, newest first within each.
        $order = $tab === 'active' ? "CASE status WHEN 'running' THEN 0 ELSE 1 END, id DESC" : 'id DESC';
        $jobs = $engine->fetchAllAssociative(
            "SELECT uuid, type, queue, site_handle, status, attempts, max_attempts, progress, error,
                    run_after, created_at, started_at, heartbeat_at, finished_at
             FROM jobs WHERE {$where} ORDER BY {$order} LIMIT " . ($tab === 'active' ? 100 : 50),
        );

        $counts = ['all' => 0, 'active' => 0, 'done' => 0, 'failed' => 0];
        foreach ($engine->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $row) {
            $n = (int) $row['n'];
            $counts['all'] += $n;
            $bucket = match ((string) $row['status']) {
                'pending', 'running' => 'active',
                'done' => 'done',
                default => 'failed',
            };
            $counts[$bucket] += $n;
        }

        $now = $this->clock->now()->getTimestamp();
        foreach ($jobs as &$j) {
            $started = $this->ts($j['started_at']);
            $j['runtime'] = null;
            $j['hb_age'] = null;
            $j['retry_in'] = null;
            if ($j['status'] === 'running' && $started !== null) {
                $j['runtime'] = $this->span($now - $started);
                $hb = $this->ts($j['heartbeat_at']);
                $j['hb_age'] = $hb !== null ? $this->span($now - $hb) : null;
            } elseif ($started !== null && ($finished = $this->ts($j['finished_at'])) !== null) {
                $j['runtime'] = $this->span($finished - $started);
            }
            if ($j['status'] === 'pending' && ($due = $this->ts($j['run_after'])) !== null && $due > $now) {
                $j['retry_in'] = $this->span($due - $now);
            }
        }
        unset($j);

        $doctor = isset($query['doctor']) ? $this->doctor->run() : null;

        return $this->dash->render($request, 'admin-ops.html.twig', [
            'title' => 'Operations — fastsite',
            'jobs' => $jobs,
            'jobs_tab' => $tab,
            'job_counts' => $counts,
            // Auto-refresh while live work is visible (not over doctor output —
            // rerunning it every few seconds would hammer git remotes).
            'jobs_live' => $doctor === null && $counts['active'] > 0 && \in_array($tab, ['active', 'all'], true),
            'builds' => $engine->fetchAllAssociative(
                'SELECT site_handle, trigger_source, scope, status, stats, error, started_at, finished_at
                 FROM builds ORDER BY id DESC LIMIT 15',
            ),
            'backups' => $this->backups->list(null, 25),
            'sites' => $this->dash->sitesFor($this->dash->user($request)),
            'doctor' => $doctor,
        ]);
    }

    private function ts(mixed $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $t = strtotime((string) $iso);

        return $t === false ? null : $t;
    }

    /** 42 → "42s", 195 → "3m 15s", 7500 → "2h 5m". */
    private function span(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }

        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
