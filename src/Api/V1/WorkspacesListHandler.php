<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Workspace\Service\WorkspaceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/workspaces — the workspaces the acting user may access (admin →
 * all; otherwise owner or attached-team member). An API key acts as its
 * owning user, so the list is identical across dashboard, REST and MCP.
 */
final class WorkspacesListHandler implements RequestHandlerInterface
{
    public function __construct(private readonly WorkspaceService $workspaces)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        return ApiResponse::success(['workspaces' => array_map(static fn (array $w): array => [
            'id' => $w['id'],
            'name' => $w['name'],
            'team_id' => $w['team_id'],
            'site_count' => $w['site_count'],
        ], $this->workspaces->listForUser($access->userId))]);
    }
}
