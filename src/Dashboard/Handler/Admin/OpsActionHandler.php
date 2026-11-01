<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /admin/ops/action — cancel/restart queue jobs, run a backup (one site,
 * the engine, or everything), restore a site (typed confirmation: the handle
 * must be re-typed), prune. Engine restore is deliberately NOT here —
 * CLI-only disaster recovery.
 */
final class OpsActionHandler implements RequestHandlerInterface
{
    private const RESTARTABLE = ['done', 'failed', 'dead', 'cancelled'];

    public function __construct(
        private readonly Dash $dash,
        private readonly JobQueue $queue,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $engine = $this->databases->engine();

        // Job actions land back on the tab they came from.
        $tab = (string) ($body['tab'] ?? '');
        $back = \in_array($tab, ['active', 'all', 'done', 'failed'], true) ? '/admin/ops?jobs=' . $tab : '/admin/ops';

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'cancel-job':
                    $uuid = (string) ($body['uuid'] ?? '');
                    $status = $engine->fetchOne('SELECT status FROM jobs WHERE uuid = ?', [$uuid]);
                    if ($status === false || !\in_array($status, ['pending', 'running'], true)) {
                        return $this->dash->redirect($back, '', 'Job not found or already finished.');
                    }
                    // Guarded on the observed status: the worker's own
                    // terminal UPDATEs are guarded on status='running', so
                    // whoever flips first wins and the loser no-ops.
                    $updated = $engine->executeStatement(
                        "UPDATE jobs SET status = 'cancelled', finished_at = ?
                         WHERE uuid = ? AND status IN ('pending', 'running')",
                        [$this->clock->iso(), $uuid],
                    );
                    if ($updated !== 1) {
                        return $this->dash->redirect($back, '', 'Job changed state — refresh and retry.');
                    }
                    $this->queue->forget($uuid);

                    return $this->dash->redirect($back, $status === 'running'
                        ? 'Job cancelled — the step already in flight may still finish its work, but nothing further runs (no retries, no chain).'
                        : 'Job cancelled.');

                case 'restart-job':
                case 'retry-job': // legacy name
                    $uuid = (string) ($body['uuid'] ?? '');
                    $row = $engine->fetchAssociative('SELECT status, dedupe_key FROM jobs WHERE uuid = ?', [$uuid]);
                    if ($row === false || !\in_array($row['status'], self::RESTARTABLE, true)) {
                        return $this->dash->redirect($back, '', 'Job not found or still active.');
                    }
                    if ($row['dedupe_key'] !== null) {
                        $twin = $engine->fetchOne(
                            "SELECT uuid FROM jobs WHERE dedupe_key = ? AND status = 'pending' AND uuid != ?",
                            [$row['dedupe_key'], $uuid],
                        );
                        if ($twin !== false) {
                            return $this->dash->redirect($back, '', 'A pending job with the same dedupe key supersedes this one.');
                        }
                    }
                    $updated = $engine->executeStatement(
                        "UPDATE jobs SET status = 'pending', attempts = 0, run_after = NULL, error = NULL,
                             progress = NULL, reserved_by = NULL, heartbeat_at = NULL, started_at = NULL, finished_at = NULL
                         WHERE uuid = ? AND status IN ('done', 'failed', 'dead', 'cancelled')",
                        [$uuid],
                    );
                    if ($updated !== 1) {
                        return $this->dash->redirect($back, '', 'Job changed state — refresh and retry.');
                    }
                    $this->queue->pushExisting($uuid);

                    return $this->dash->redirect($back, 'Job restarted.');

                case 'backup-now':
                    $handle = (string) ($body['site'] ?? '');
                    if ($handle === '__all__') {
                        $this->queue->enqueue('backup_engine', null, ['trigger' => 'dashboard'], 'low', 'backup:engine');
                        $handles = $engine->fetchFirstColumn('SELECT handle FROM sites WHERE deleted_at IS NULL ORDER BY handle');
                        foreach ($handles as $h) {
                            $this->queue->enqueue('backup_site', (string) $h, ['trigger' => 'dashboard', 'kind' => 'full'], 'low', 'backup:full:' . (string) $h);
                        }

                        return $this->dash->redirect('/admin/ops', 'Backups queued: engine + ' . \count($handles) . ' site(s).');
                    }
                    if ($handle === '__engine__') {
                        $this->queue->enqueue('backup_engine', null, ['trigger' => 'dashboard'], 'low', 'backup:engine');

                        return $this->dash->redirect('/admin/ops', 'Engine backup queued.');
                    }
                    if ($this->siteExists($handle)) {
                        $this->queue->enqueue('backup_site', $handle, ['trigger' => 'dashboard', 'kind' => 'full'], 'low', 'backup:full:' . $handle);

                        return $this->dash->redirect('/admin/ops', 'Full backup of ' . $handle . ' queued.');
                    }

                    return $this->dash->redirect('/admin/ops', '', 'No such site.');

                case 'restore-site':
                    $handle = (string) ($body['site'] ?? '');
                    $confirm = trim((string) ($body['confirm'] ?? ''));
                    if (!$this->siteExists($handle)) {
                        return $this->dash->redirect('/admin/ops', '', 'No such site.');
                    }
                    // Typed confirmation: the operator must re-type the handle.
                    if ($confirm !== $handle) {
                        return $this->dash->redirect('/admin/ops', '', 'Type the site handle exactly to confirm the restore.');
                    }
                    $payload = ['trigger' => 'dashboard'];
                    if (($body['with_media'] ?? '') === '1') {
                        $payload['media'] = true;
                    }
                    if (($body['with_shell'] ?? '') === '1') {
                        $payload['shell'] = true;
                    }
                    $uuid = $this->queue->enqueue('restore_site', $handle, $payload, 'high');

                    return $this->dash->redirect('/admin/ops', 'Restore of ' . $handle . ' queued (job ' . $uuid . '). It rebuilds + publishes when done.');

                case 'prune-backups':
                    $this->queue->enqueue('prune_backups', null, ['trigger' => 'dashboard'], 'low', 'prune:backups');

                    return $this->dash->redirect('/admin/ops', 'Prune queued.');

                default:
                    return $this->dash->redirect('/admin/ops', '', 'Unknown action.');
            }
        } catch (UniqueConstraintViolationException) {
            // Restart raced a concurrent enqueue back into the pending
            // dedupe slot — the twin does the work, nothing lost.
            return $this->dash->redirect($back, '', 'A pending job with the same dedupe key supersedes this one.');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/admin/ops', '', $e->getMessage());
        }
    }

    private function siteExists(string $handle): bool
    {
        return $handle !== ''
            && $this->databases->engine()->fetchOne('SELECT 1 FROM sites WHERE handle = ?', [$handle]) !== false;
    }
}
