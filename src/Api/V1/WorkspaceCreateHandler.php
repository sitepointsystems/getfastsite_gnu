<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Team\Service\TeamService;
use Fastsite\Workspace\Service\WorkspaceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/workspaces — create a workspace ({name, team_id?}). Admins and
 * editors only (mirrors site creation); a non-admin may only attach a team
 * they belong to (403 otherwise — THE workspace guard on every transport).
 */
final class WorkspaceCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly TeamService $teams,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        $role = (string) $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$access->userId]);
        if (!\in_array($role, ['admin', 'editor'], true)) {
            return ApiResponse::error('Your account cannot create workspaces — ask an admin to make you an editor.', 403);
        }

        try {
            $body = ApiHelpers::jsonBody($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $teamId = (int) ($body['team_id'] ?? 0);
        if ($teamId > 0 && $role !== 'admin' && !$this->teams->isMember($teamId, $access->userId)) {
            return ApiResponse::error('You can only attach a workspace to a team you belong to.', 403);
        }

        try {
            $id = $this->workspaces->create($access->userId, (string) ($body['name'] ?? ''), $teamId > 0 ? $teamId : null);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $workspace = $this->workspaces->find($id);
        \assert($workspace !== null);

        return ApiResponse::success([
            'id' => $workspace['id'],
            'name' => $workspace['name'],
            'team_id' => $workspace['team_id'],
        ], 201);
    }
}
