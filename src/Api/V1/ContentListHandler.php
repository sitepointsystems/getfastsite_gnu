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
        private readonly \Fastsite\Category\Repository\CategoryRepository $categories,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 20)));

        // ?category=<slug> narrows to one category's posts. Resolved through
        // the repository directly: an unknown slug is a 422, not an empty list
        // that looks like "this category has no posts".
        $categoryId = null;
        if (($query['category'] ?? '') !== '') {
            $category = $this->categories->findBySlug($handle, (string) $query['category']);
            if ($category === null) {
                return ApiResponse::error('Unknown category: ' . (string) $query['category'], 422);
            }
            $categoryId = $category->id;
        }

        $result = $this->content->listFiltered(
            $handle,
            isset($query['type']) ? (string) $query['type'] : null,
            isset($query['status']) ? (string) $query['status'] : null,
            $page,
            $perPage,
            null,
            $categoryId,
        );

        return ApiResponse::paginated(
            array_map(static fn ($c): array => ApiHelpers::contentToArray($c, $site), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }
}
