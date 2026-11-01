<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites — create a new site (scope `admin`). Provisions its
 * database, scaffolds the default shell, and registers it. The handle is the
 * immutable identity used on every transport and the on-disk path.
 *
 * Body: {handle, name?, base_url?, github_repo?, primary_locale?}
 */
final class SiteCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteService $siteService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        $body = ApiHelpers::jsonBody($request);
        $handle = trim((string) ($body['handle'] ?? ''));
        if ($handle === '') {
            return ApiResponse::error('handle is required.', 422);
        }

        try {
            $site = $this->siteService->create(
                $handle,
                (string) ($body['name'] ?? $handle),
                (string) ($body['base_url'] ?? ''),
                (string) ($body['github_repo'] ?? ''),
                (string) ($body['primary_locale'] ?? 'en'),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            // Duplicate handle (already exists).
            return ApiResponse::error($e->getMessage(), 409);
        }

        $this->audit->log($access, 'site.create', $handle, 'site:' . $handle, ['handle' => $handle], ApiHelpers::clientIp($request));

        return ApiResponse::success([
            'handle' => $site->handle,
            'name' => $site->name,
            'base_url' => $site->baseUrl,
            'primary_locale' => $site->primaryLocale,
            'status' => $site->status,
            // Off by default: a new site is indexable from its first publish.
            'development' => $site->development,
        ], 201);
    }
}
