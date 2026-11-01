<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /workspaces — manage the customer-separation layer (mirrors /teams):
 * create/rename/delete workspaces, attach a team, and see which sites each
 * one holds. Selection stays in the sidebar switcher; sites are moved from
 * the overview card menu or a site's Settings screen.
 */
final class WorkspacesScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly WorkspaceService $workspaces,
        private readonly TeamService $teams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->dash->user($request);
        $workspaces = $this->workspaces->listForUser((int) $user['id']);

        // The sites grouped under each workspace — from the user's own
        // accessible set, so nothing foreign leaks into the listing.
        $sitesByWorkspace = [];
        foreach ($this->dash->sitesFor($user) as $site) {
            if ($site->workspaceId !== null) {
                $sitesByWorkspace[$site->workspaceId][] = $site;
            }
        }

        return $this->dash->render($request, 'workspaces.html.twig', [
            'title' => 'Workspaces — fastsite',
            'ws_list' => $workspaces,
            'sites_by_workspace' => $sitesByWorkspace,
            'user_teams' => $this->teams->listForUser((int) $user['id']),
        ]);
    }
}
