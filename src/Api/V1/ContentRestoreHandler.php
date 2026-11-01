<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/content/{id}/restore — un-trash a soft-deleted
 * item. Fails (409) if a live sibling now occupies the same slug.
 */
final class ContentRestoreHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ContentService $content,
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

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $id = (int) $request->getAttribute('id');

        try {
            $restored = $this->content->restore($site, $id);
        } catch (\RuntimeException $e) {
            // "not found" vs "slug conflict" both throw RuntimeException; a
            // conflict is a 409, a missing row a 404.
            $status = str_contains($e->getMessage(), 'already uses') ? 409 : 404;

            return ApiResponse::error($e->getMessage(), $status);
        }

        $this->audit->log($access, 'content.restore', $handle, 'content:' . $id, [], ApiHelpers::clientIp($request));

        return ApiResponse::success(ApiHelpers::contentToArray($restored, $site));
    }
}
