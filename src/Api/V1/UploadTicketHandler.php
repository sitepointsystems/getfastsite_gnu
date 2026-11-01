<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Media\Service\UploadTicketService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/upload-tickets — mints a single-use upload URL.
 *
 * The REST twin of the MCP `create_upload_ticket`. A key-holding caller can
 * already POST multipart straight at /media, so this exists for the case where
 * the thing holding the bytes is NOT the thing holding the credential: a
 * browser, a sandbox, a CI step, a person. The ticket is the only thing that
 * has to travel to them, and it can do exactly one upload to one site.
 */
final class UploadTicketHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly UploadTicketService $tickets,
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

        $body = ApiHelpers::jsonBody($request);
        $maxBytes = isset($body['max_bytes']) ? (int) $body['max_bytes'] : null;

        $ticket = $this->tickets->create(
            $site->handle,
            $access->actorType,
            $access->userId,
            (string) ($body['filename'] ?? ''),
            (string) ($body['alt'] ?? ''),
            (bool) ($body['decorative'] ?? false),
            $maxBytes,
        );

        return ApiResponse::json(['success' => true, 'data' => $ticket], 201);
    }
}
