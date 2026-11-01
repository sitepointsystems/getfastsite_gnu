<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SitesListHandler implements RequestHandlerInterface
{
    public function __construct(private readonly SiteRepository $sites)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        $visible = [];
        foreach ($this->sites->all() as $site) {
            if (!$access->allowsSite($site->handle)) {
                continue;
            }
            $visible[] = [
                'handle' => $site->handle,
                'name' => $site->name,
                'base_url' => $site->baseUrl,
                'primary_locale' => $site->primaryLocale,
                'locales' => $site->locales,
                'status' => $site->status,
                'development' => $site->development,
                'workspace_id' => $site->workspaceId,
            ];
        }

        return ApiResponse::success(['sites' => $visible]);
    }
}
