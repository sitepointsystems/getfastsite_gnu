<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Category\Dto\Category;
use Fastsite\Category\Service\CategoryService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Post categories over REST, one handler dispatched by method:
 *   GET    /api/v1/sites/{site}/categories          (content:read)  — list
 *   GET    /api/v1/sites/{site}/categories/{slug}   (content:read)  — one
 *   PUT    /api/v1/sites/{site}/categories/{slug}   (content:write) — create or update;
 *          a `slug` in the body renames it (301s recorded)
 *   DELETE /api/v1/sites/{site}/categories/{slug}   (content:write) — refused (422) while posts use it
 * Every write republishes (CategoryService).
 */
final class CategoriesHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly CategoryService $categories,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $slug = (string) ($request->getAttribute('slug') ?? '');
        $method = strtoupper($request->getMethod());

        if ($method === 'GET' && $slug === '') {
            return ApiResponse::success([
                'categories' => array_map(fn (Category $c): array => self::toArray($site, $c), $this->categories->list($site)),
            ]);
        }
        if ($method === 'GET') {
            $category = $this->categories->find($site, $slug);

            return $category === null
                ? ApiResponse::error('Unknown category: ' . $slug, 404)
                : ApiResponse::success(self::toArray($site, $category));
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        try {
            if ($method === 'DELETE') {
                $this->categories->delete($site, $slug);
                $this->audit->log($access, 'category.delete', $handle, 'category:' . $slug, [], ApiHelpers::clientIp($request));

                return ApiResponse::success(['deleted' => true]);
            }

            $body = array_intersect_key(
                ApiHelpers::jsonBody($request),
                array_flip(['name', 'slug', 'description_html', 'seo_title', 'seo_description']),
            );
            $result = $this->categories->upsert($site, $slug, $body);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }

        $this->audit->log($access, $result['created'] ? 'category.create' : 'category.update', $handle, 'category:' . $result['category']->slug, [
            'redirects' => $result['redirects'],
        ], ApiHelpers::clientIp($request));

        return ApiResponse::success(self::toArray($site, $result['category']) + [
            'created' => $result['created'],
            'redirects_recorded' => $result['redirects'],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Site $site, Category $c): array
    {
        $path = SiteLinks::categoryPath($site, $c->slug);

        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'description_html' => $c->descriptionHtml,
            'seo_title' => $c->seoTitle,
            'seo_description' => $c->seoDescription,
            'path' => $path,
            'url' => SiteLinks::absolute($site, $path),
            'created_at' => $c->createdAt,
            'updated_at' => $c->updatedAt,
        ];
    }
}
