<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/sites/{site}/content/trash — list soft-deleted content
 * (optional ?type=post|page).
 */
final class ContentTrashHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ContentService $content,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $type = $request->getQueryParams()['type'] ?? null;
        $type = \in_array($type, ['post', 'page'], true) ? $type : null;

        $items = array_map(ApiHelpers::contentToArray(...), $this->content->listTrashed($site, $type));

        return ApiResponse::success(['items' => $items, 'total' => \count($items)]);
    }
}
