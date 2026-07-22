<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ContentListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ContentRepository $content,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        if ($this->sites->findByHandle($handle) === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->content->listFiltered(
            $handle,
            isset($query['type']) ? (string) $query['type'] : null,
            isset($query['status']) ? (string) $query['status'] : null,
            $page,
            $perPage,
        );

        return ApiResponse::paginated(
            array_map(ApiHelpers::contentToArray(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }
}
