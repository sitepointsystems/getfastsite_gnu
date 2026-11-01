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
        'canonical_host', 'github_credential_id', 'team_id', 'workspace_id',
        'shortpixel_key', 'fact_check_enabled',
        'seo_title_suffix', 'seo_title_separator',
        'sanitize_content', 'cta_tracking', 'development', 'publish_all_media',
        'translate_concurrency', 'translate_chunk_size',
        'structured_data', 'schema_type', 'schema_same_as',
        // Deploy target. serve_mode was reachable over MCP and the dashboard
        // but not here, so REST could not switch a site between publishing
        // modes at all — the ftp fields would have been unreachable with it.
        'serve_mode',
        'ftp_protocol', 'ftp_host', 'ftp_port', 'ftp_username',
        'ftp_password', 'ftp_key', 'ftp_remote_path', 'ftp_verify_cert',
        // Mail. These were reachable ONLY from the dashboard while the FTP
        // credentials beside them were reachable from everywhere, so an agent
        // could re-target a site's entire deploy target over the API but could
        // not configure the mail that makes its forms work. The FTP password
        // already settled whether this surface accepts a secret; the omission
        // was an oversight, not a position.
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
        'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_verify_cert',
        // Form spam guard.
        'spam_mode', 'spam_retention_days', 'turnstile_site_key', 'turnstile_secret',
        // Permalinks. A change records a 301 for every URL it moves.
        'post_path_pattern', 'category_path_pattern', 'archive_per_page',
    ];

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteService $siteService,
        private readonly PublishTrigger $publish,
        private readonly AuditLogger $audit,
        private readonly \Fastsite\Workspace\Service\WorkspaceService $workspaces,
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

        // Moving the site into a workspace requires access to the TARGET
        // workspace (admin, owner, or attached-team member) — 403 otherwise.
        // A nonexistent workspace id fails the same check.
        if (\array_key_exists('workspace_id', $fields)) {
            $target = $fields['workspace_id'];
            $targetId = ($target === null || $target === '' || (int) $target <= 0) ? null : (int) $target;
            if ($targetId !== null && $targetId !== $before->workspaceId
                && !$this->workspaces->canAccess($access->userId, $targetId)) {
                return ApiResponse::error('You do not have access to workspace: ' . $targetId, 403);
            }
        }

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
            'development' => $after->development,
            'publish_all_media' => $after->publishAllMedia,
            'favicon_media_id' => $after->faviconMediaId,
            'logo_header_media_id' => $after->logoHeaderMediaId,
            'logo_footer_media_id' => $after->logoFooterMediaId,
            'social_image_media_id' => $after->socialImageMediaId,
            'meta_description' => $after->metaDescription,
        ]);
    }
}
