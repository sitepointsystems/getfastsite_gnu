<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /admin/ops/action — retry a dead/failed job, run a backup, restore a
 * site (typed confirmation: the handle must be re-typed), prune. Engine
 * restore is deliberately NOT here — CLI-only disaster recovery.
 */
final class OpsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly JobQueue $queue,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $engine = $this->databases->engine();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'retry-job':
                    $uuid = (string) ($body['uuid'] ?? '');
                    $row = $engine->fetchAssociative('SELECT status, dedupe_key FROM jobs WHERE uuid = ?', [$uuid]);
                    if ($row === false || !\in_array($row['status'], ['dead', 'failed'], true)) {
                        return $this->dash->redirect('/admin/ops', '', 'Job not found or not dead/failed.');
                    }
                    if ($row['dedupe_key'] !== null) {
                        $twin = $engine->fetchOne(
                            "SELECT uuid FROM jobs WHERE dedupe_key = ? AND status = 'pending' AND uuid != ?",
                            [$row['dedupe_key'], $uuid],
                        );
                        if ($twin !== false) {
                            return $this->dash->redirect('/admin/ops', '', 'A pending job with the same dedupe key supersedes this one.');
                        }
                    }
                    $engine->executeStatement(
                        "UPDATE jobs SET status = 'pending', attempts = 0, run_after = NULL, error = NULL WHERE uuid = ?",
                        [$uuid],
                    );
                    $this->queue->pushExisting($uuid);

                    return $this->dash->redirect('/admin/ops', 'Job requeued.');

                case 'backup-now':
                    $handle = (string) ($body['site'] ?? '');
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
