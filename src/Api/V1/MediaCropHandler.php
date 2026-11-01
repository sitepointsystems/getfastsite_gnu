<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/media/{id}/crop — set or clear an image's
 * non-destructive crop and target width.
 *
 * The uploaded original is never modified: the rect is stored and re-applied
 * by the pipeline every time variants are built, so this is re-runnable and
 * reversible. It still republishes, because the emitted filenames move
 * (`/media/*` is immutable-cached) and every page embedding the id follows
 * the new framing with nothing to re-embed.
 *
 * Body: {"crop": {"x":0.1,"y":0,"w":0.8,"h":0.5}, "resize_width": 1200}
 *       {"clear": true} to go back to the full image.
 * The rect is FRACTIONS of the original, 0..1 — not pixels, and not of the
 * currently cropped view.
 */
final class MediaCropHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly MediaService $media,
        private readonly \Fastsite\Build\Service\PublishTrigger $publish,
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
            $body = ApiHelpers::jsonBody($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $clear = (bool) ($body['clear'] ?? false);
        $crop = null;
        if (!$clear && isset($body['crop']) && \is_array($body['crop'])) {
            $crop = $body['crop'];
        }
        $width = $clear ? null : (int) ($body['resize_width'] ?? 0);

        try {
            $item = $this->media->transform($site, $id, $crop, $width);
        } catch (\Fastsite\Media\MediaNotFoundException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }

        $this->publish->trigger($site, 'publish: image #' . $id . ' reframed (api)', 'api-crop');
        $this->audit->log($access, 'media.crop', $handle, 'media:' . $id, [
            'clear' => $clear,
            'width' => $item->width,
            'height' => $item->height,
        ], ApiHelpers::clientIp($request));

        $path = SiteLinks::mediaPath($item);

        return ApiResponse::success([
            'id' => $item->id,
            'width' => $item->width,
            'height' => $item->height,
            'revision' => $item->revision,
            'crop' => $item->sharpTransform()['crop'] ?? null,
            'resize_width' => $item->resizeWidth,
            'path' => $path,
            'url' => $path === null ? null : SiteLinks::absolute($site, $path),
            'note' => 'The original is untouched — send {"clear":true} to go back to the full image. Rebuild + publish queued.',
        ]);
    }
}
