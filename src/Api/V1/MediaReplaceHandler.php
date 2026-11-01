<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/media/{id}/replace — swap the CONTENT behind an
 * existing media id. The id, the name and the alt survive, so every
 * `<img data-media-id>` and `{{ filename }}` embed already pointing at it
 * shows the new file with nothing to re-embed.
 *
 * Same three intake shapes as the upload endpoint (multipart `file`, JSON
 * `data` base64, JSON `url`), and the site republishes: the pages that
 * reference it render a new URL for it (the emitted filename carries the
 * revision, because /media/* is served immutable for a year).
 */
final class MediaReplaceHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
        private readonly PublishTrigger $publish,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }
        $id = (int) $request->getAttribute('id');

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        try {
            $media = $this->replace($request, $site, $id);
        } catch (\Fastsite\Media\MediaNotFoundException $e) {
            // Not 502: no amount of retrying makes a missing id appear.
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 502);
        }

        $this->publish->trigger($site, 'publish: media #' . $id . ' replaced', 'media-replace');
        $this->audit->log($access, 'media.replace', $handle, 'media:' . $media->id, [
            'kind' => $media->kind, 'bytes' => $media->bytes, 'revision' => $media->revision,
        ], ApiHelpers::clientIp($request));

        return ApiResponse::success($this->toArray($media));
    }

    private function replace(ServerRequestInterface $request, \Fastsite\Site\Dto\Site $site, int $id): Media
    {
        $files = $request->getUploadedFiles();
        if (isset($files['file']) && $files['file'] instanceof UploadedFileInterface) {
            $file = $files['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Upload failed (error code ' . $file->getError() . ').');
            }
            $size = $file->getSize();
            if ($size !== null && $size > MediaService::maxUploadBytes()) {
                throw new \InvalidArgumentException('File exceeds the maximum upload size.');
            }
            $params = $request->getParsedBody();
            $alt = \is_array($params) && trim((string) ($params['alt'] ?? '')) !== '' ? (string) $params['alt'] : null;

            return $this->media->replace($site, $id, (string) $file->getStream(), $file->getClientFilename(), $alt);
        }

        $body = ApiHelpers::jsonBody($request);
        $alt = trim((string) ($body['alt'] ?? '')) !== '' ? (string) $body['alt'] : null;

        $data = (string) ($body['data'] ?? '');
        if (trim($data) !== '') {
            return $this->media->replaceFromBase64($site, $id, $data, (string) ($body['filename'] ?? ''), $alt);
        }

        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('Provide a multipart `file`, a JSON `data` (base64), or a JSON `url`.');
        }

        return $this->media->replaceFromUrl($site, $id, $url, $alt, null, (string) ($body['filename'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Media $media): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'original_name' => $media->originalName,
            'mime' => $media->mime,
            'bytes' => $media->bytes,
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
            'status' => $media->status,
            'revision' => $media->revision,
            'variant_count' => \count($media->variants),
        ];
    }
}
