<?php

declare(strict_types=1);

namespace Fastsite\Media\Handler;

use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\UploadTicketService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /u/{token} — redeems an upload ticket with the raw bytes of one file.
 *
 * The ticket IS the auth, exactly as the HMAC is the auth on a webhook: it was
 * minted through the normal site-scoped authz, it carries the site and the
 * actor, it is single-use and it expires. So this route takes no auth
 * middleware, and must not — anything that parsed or authenticated the request
 * first would be reading a body that is deliberately just bytes.
 *
 * The body is RAW, not multipart, because that is the shape every client can
 * produce in one line:
 *
 *     curl --upload-file photo.jpg "<upload_url>"
 *
 * A multipart variant was considered and left out: it would mean two body
 * shapes to document for a surface whose entire reason to exist is that the
 * caller has no good options, and the one-liner is the thing an agent can be
 * told to run. Browsers can PUT a File object directly with fetch().
 */
final class TicketUploadHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UploadTicketService $tickets,
        private readonly SiteRepository $sites,
        private readonly MediaService $media,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $request->getAttribute('token');

        try {
            $ticket = $this->tickets->claim($token);
        } catch (\InvalidArgumentException $e) {
            // 404 rather than 401: an unknown, spent or expired token is not a
            // failed authentication the caller can fix by trying again, and
            // distinguishing "wrong" from "used up" to an anonymous caller
            // would make this an oracle for guessing live tokens.
            return ApiResponse::error($e->getMessage(), 404);
        }

        $site = $this->sites->findByHandle($ticket['site_handle']);
        if ($site === null) {
            return ApiResponse::error('The site this ticket was minted for no longer exists.', 410);
        }

        $bytes = (string) $request->getBody();
        if ($bytes === '') {
            return ApiResponse::error('Empty body — PUT the file bytes themselves, e.g. curl --upload-file photo.jpg "<upload_url>".', 422);
        }
        if (\strlen($bytes) > $ticket['max_bytes']) {
            return ApiResponse::error(sprintf('File exceeds this ticket\'s %d MB limit.', intdiv($ticket['max_bytes'], 1048576)), 413);
        }

        try {
            $media = $this->media->ingest(
                $site,
                $bytes,
                // Passed through as-is, empty included: ingest() sniffs a known
                // image or video type when the name carries no extension, so a
                // ticket holder with no filename gets an <img>-able image
                // rather than a download link.
                $ticket['filename'],
                $ticket['alt'],
                $ticket['decorative'],
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 502);
        }

        $this->tickets->complete($ticket['id'], $media->id);

        return ApiResponse::json(['success' => true, 'data' => [
            'id' => $media->id,
            'kind' => $media->kind,
            'original_name' => $media->originalName,
            'mime' => $media->mime,
            'bytes' => $media->bytes,
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
            'status' => $media->status,
            'path' => SiteLinks::mediaPath($media),
            'url' => SiteLinks::mediaUrl($site, $media),
            'use_in_body' => $media->kind === 'image'
                ? '<img data-media-id="' . $media->id . '" alt="' . htmlspecialchars($media->alt, ENT_QUOTES) . '">'
                : null,
        ]], 201);
    }
}
