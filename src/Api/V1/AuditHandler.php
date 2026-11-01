<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Build\Service\PageAuditLog;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/sites/{site}/audit — what PageAudit found on the last build.
 * Scope sites:read.
 *
 * The same read the dashboard Audit screen makes. It is on the API because
 * the things the audit catches — a canonical on the wrong host, a favicon
 * Google cannot render, a missing meta description — are overwhelmingly
 * caused by whoever authored the shell, and that is increasingly an agent
 * rather than a person looking at a screen.
 */
final class AuditHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PageAuditLog $auditLog,
        private readonly SiteRepository $sites,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        // ScopeCheckMiddleware already pins {site} to the key's sites, but
        // don't leak the existence of a site the key cannot see.
        if ($this->sites->findByHandle($handle) === null || !$access->allowsSite($handle)) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        return ApiResponse::success($this->auditLog->latest($handle));
    }
}
