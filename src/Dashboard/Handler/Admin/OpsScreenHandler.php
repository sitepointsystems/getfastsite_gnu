<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Backup\Service\BackupService;
use Fastsite\Dashboard\Dash;
use Fastsite\Ops\DoctorService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /admin/ops — screen 8: jobs, build history, backups, doctor (doctor
 * runs only on ?doctor=1 — it probes git remotes and takes seconds).
 */
final class OpsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
        private readonly BackupService $backups,
        private readonly DoctorService $doctor,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $engine = $this->databases->engine();
        $query = $request->getQueryParams();

        return $this->dash->render($request, 'admin-ops.html.twig', [
            'title' => 'Operations — fastsite',
            'jobs' => $engine->fetchAllAssociative(
                'SELECT uuid, type, queue, site_handle, status, attempts, error, created_at, finished_at
                 FROM jobs ORDER BY id DESC LIMIT 30',
            ),
            'builds' => $engine->fetchAllAssociative(
                'SELECT site_handle, trigger_source, scope, status, stats, error, started_at, finished_at
                 FROM builds ORDER BY id DESC LIMIT 15',
            ),
            'backups' => $this->backups->list(null, 25),
            'sites' => $this->dash->sitesFor($this->dash->user($request)),
            'doctor' => isset($query['doctor']) ? $this->doctor->run() : null,
        ]);
    }
}
