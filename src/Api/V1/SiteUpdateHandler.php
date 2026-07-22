<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PATCH /api/v1/sites/{site} — update site settings (name, base_url,
 * github_repo/branch, media_public_base, status, favicon/logo media ids).
 * Only provided keys change. Republishes when a change affects the built
 * output (name/base_url/branding).
 */
final class SiteUpdateHandler implements RequestHandlerInterface
{
    private const EDITABLE = [
        'name', 'base_url', 'github_repo', 'github_branch', 'media_public_base', 'status',
        'favicon_media_id', 'logo_header_media_id', 'logo_footer_media_id',
        'social_image_media_id', 'meta_description',
        'canonical_host', 'github_credential_id', 'team_id',
        'shortpixel_key', 'fact_check_enabled',
    ];

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteService $siteService,
        private readonly PublishTrigger $publish,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $before = $this->sites->findByHandle($handle);
        if ($before === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        $body = ApiHelpers::jsonBody($request);
        $fields = array_intersect_key($body, array_flip(self::EDITABLE));

        try {
            $after = $this->siteService->update($handle, $fields);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }

        if (SiteService::changeNeedsRebuild($before, $after)) {
            $this->publish->trigger($after, 'publish: site settings changed (api)', 'api');
        }

        $this->audit->log($access, 'site.update', $handle, 'site:' . $handle, array_keys($fields), ApiHelpers::clientIp($request));

        return ApiResponse::success([
            'handle' => $after->handle,
            'name' => $after->name,
            'base_url' => $after->baseUrl,
            'github_repo' => $after->githubRepo,
            'github_branch' => $after->githubBranch,
            'status' => $after->status,
            'favicon_media_id' => $after->faviconMediaId,
            'logo_header_media_id' => $after->logoHeaderMediaId,
            'logo_footer_media_id' => $after->logoFooterMediaId,
            'social_image_media_id' => $after->socialImageMediaId,
            'meta_description' => $after->metaDescription,
        ]);
    }
}
