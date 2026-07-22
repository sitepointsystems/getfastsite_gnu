<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/sites/{site}/media/{id}/purge — permanently delete an item,
 * its variants, and its files on disk. Irreversible.
 */
final class MediaPurgeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly MediaService $media,
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
            $this->media->purge($site, $id);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }

        $this->audit->log($access, 'media.purge', $handle, 'media:' . $id, [], ApiHelpers::clientIp($request));

        return ApiResponse::success(['id' => $id, 'purged' => true]);
    }
}
