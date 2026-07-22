<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET / — screen 1: sites overview (status, content counts, last build). */
final class OverviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->dash->user($request);
        $trash = ($request->getQueryParams()['trash'] ?? '') === '1';
        $engine = $this->databases->engine();
        $cards = [];

        $sites = $trash ? $this->dash->deletedSitesFor($user) : $this->dash->sitesFor($user);
        foreach ($sites as $site) {
            $counts = ['published' => 0, 'draft' => 0];
            try {
                foreach ($this->databases->site($site->handle)->fetchAllAssociative(
                    "SELECT status, COUNT(*) n FROM content WHERE deleted_at IS NULL GROUP BY status",
                ) as $row) {
                    $counts[(string) $row['status']] = (int) $row['n'];
                }
            } catch (\Throwable) {
                // A broken site DB must not blank the overview.
            }

            $lastBuild = $engine->fetchAssociative(
                "SELECT status, finished_at FROM builds WHERE site_handle = ? ORDER BY id DESC LIMIT 1",
                [$site->handle],
            );

            $cards[] = [
                'site' => $site,
                'counts' => $counts,
                'last_build' => $lastBuild !== false ? $lastBuild : null,
            ];
        }

        return $this->dash->render($request, 'overview.html.twig', [
            'title' => ($trash ? 'Trash — ' : 'Sites — ') . 'fastsite',
            'cards' => $cards,
            'trash' => $trash,
            'trash_count' => \count($this->dash->deletedSitesFor($user)),
        ]);
    }
}
