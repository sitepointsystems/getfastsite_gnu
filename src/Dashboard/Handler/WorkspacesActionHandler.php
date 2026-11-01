<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Site\Service\SiteService;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /workspaces/action — the ONLY way a workspace gets selected or a site
 * moved between workspaces on the dashboard (deliberately no GET/query-string
 * variant; the selection lives in the fs_ws cookie this handler sets).
 * Every action on a workspace the user cannot access is a hard 403 — same
 * rule the REST and MCP transports enforce.
 */
final class WorkspacesActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly WorkspaceService $workspaces,
        private readonly SiteService $sites,
        private readonly TeamService $teams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $userId = (int) $user['id'];

        switch ((string) ($body['action'] ?? '')) {
            case 'select':
                $raw = trim((string) ($body['workspace'] ?? ''));
                if ($raw === '' || $raw === 'all') {
                    return $this->withCookie($this->dash->redirect('/', 'Showing all workspaces.'), null);
                }
                $id = ctype_digit($raw) ? (int) $raw : 0;
                if (!$this->workspaces->canAccess($userId, $id)) {
                    return $this->dash->forbidden('You do not have access to that workspace.');
                }
                $workspace = $this->workspaces->find($id);
                \assert($workspace !== null);

                return $this->withCookie(
                    $this->dash->redirect('/', 'Workspace: ' . $workspace['name'] . '.'),
                    $id,
                );

            case 'create':
                if (!\in_array($user['role'], ['admin', 'editor'], true)) {
                    return $this->dash->redirect('/workspaces', '', 'Your account cannot create workspaces — ask an admin to make you an editor.');
                }
                $teamId = (int) ($body['team_id'] ?? 0);
                if ($teamId > 0 && $user['role'] !== 'admin' && !$this->teams->isMember($teamId, $userId)) {
                    return $this->dash->forbidden('You can only attach a workspace to a team you belong to.');
                }
                try {
                    $id = $this->workspaces->create($userId, (string) ($body['name'] ?? ''), $teamId > 0 ? $teamId : null);
                } catch (\InvalidArgumentException $e) {
                    return $this->dash->redirect('/workspaces', '', $e->getMessage());
                }

                // Jump straight into the new (empty) workspace.
                return $this->withCookie($this->dash->redirect('/workspaces', 'Workspace created — and selected, so new sites land in it.'), $id);

            case 'update':
                $id = (int) ($body['id'] ?? 0);
                if (!$this->workspaces->canManage($userId, $id)) {
                    return $this->dash->forbidden('Only administrators or the workspace owner can change it.');
                }
                $teamId = (int) ($body['team_id'] ?? 0);
                if ($teamId > 0 && $user['role'] !== 'admin' && !$this->teams->isMember($teamId, $userId)) {
                    return $this->dash->forbidden('You can only attach a workspace to a team you belong to.');
                }
                try {
                    $this->workspaces->update($id, (string) ($body['name'] ?? ''), $teamId > 0 ? $teamId : null);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    return $this->dash->redirect('/workspaces', '', $e->getMessage());
                }

                return $this->dash->redirect('/workspaces', 'Workspace saved.');

            case 'delete':
                $id = (int) ($body['id'] ?? 0);
                if (!$this->workspaces->canManage($userId, $id)) {
                    return $this->dash->forbidden('Only administrators or the workspace owner can delete it.');
                }
                // Read the selection BEFORE deleting — afterwards the access
                // check can no longer match the dead id, and the stale cookie
                // would linger.
                $wasSelected = $this->dash->selectedWorkspaceId($request) === $id;
                $this->workspaces->delete($id);
                $response = $this->dash->redirect('/workspaces', 'Workspace deleted — its sites are back under "All workspaces".');

                return $wasSelected ? $this->withCookie($response, null) : $response;

            case 'move-site':
                $site = $this->dash->siteFor($request, trim((string) ($body['handle'] ?? '')));
                if ($site === null) {
                    return $this->dash->notFound();
                }
                if (!$this->dash->canManageSite($user, $site)) {
                    return $this->dash->forbidden('Only administrators or the site owner can move the site between workspaces.');
                }
                $raw = trim((string) ($body['workspace_id'] ?? ''));
                $target = $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
                if ($target !== null && !$this->workspaces->canAccess($userId, $target)) {
                    return $this->dash->forbidden('You do not have access to that workspace.');
                }
                $this->sites->update($site->handle, ['workspace_id' => $target]);
                if ($target === null) {
                    return $this->dash->redirect('/', $site->name . ' removed from its workspace.');
                }
                $workspace = $this->workspaces->find($target);
                \assert($workspace !== null);

                return $this->dash->redirect('/', $site->name . ' moved to "' . $workspace['name'] . '".');

            default:
                return $this->dash->redirect('/', '', 'Unknown action.');
        }
    }

    /** Persists the selection: a workspace id, or null for "all" (cookie cleared). */
    private function withCookie(ResponseInterface $response, ?int $workspaceId): ResponseInterface
    {
        $cookie = $workspaceId === null
            ? Dash::WORKSPACE_COOKIE . '=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly'
            : Dash::WORKSPACE_COOKIE . '=' . $workspaceId . '; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly';

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
