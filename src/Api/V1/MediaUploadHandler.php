<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Accepts a multipart file upload (field `file`) OR a JSON body with
 * `url` (SSRF-guarded import). alt is required for images unless
 * decorative=true.
 */
final class MediaUploadHandler implements RequestHandlerInterface
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

        try {
            $media = $this->ingest($request, $site);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 502);
        }

        $this->audit->log($access, 'media.upload', $handle, 'media:' . $media->id, [
            'kind' => $media->kind, 'bytes' => $media->bytes,
        ], ApiHelpers::clientIp($request));

        return ApiResponse::json(['success' => true, 'data' => $this->toArray($media)], 201);
    }

    private function ingest(ServerRequestInterface $request, \Fastsite\Site\Dto\Site $site): Media
    {
        $files = $request->getUploadedFiles();
        if (isset($files['file']) && $files['file'] instanceof UploadedFileInterface) {
            $file = $files['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Upload failed (error code ' . $file->getError() . ').');
            }
            // Reject an over-cap upload before materializing it into memory.
            $size = $file->getSize();
            if ($size !== null && $size > MediaService::maxUploadBytes()) {
                throw new \InvalidArgumentException('File exceeds the maximum upload size.');
            }
            $params = $request->getParsedBody();
            $alt = \is_array($params) ? (string) ($params['alt'] ?? '') : '';
            $decorative = \is_array($params) && (($params['decorative'] ?? '') === 'true' || ($params['decorative'] ?? '') === '1');

            return $this->media->ingest(
                $site,
                (string) $file->getStream(),
                $file->getClientFilename() ?? 'upload',
                $alt,
                $decorative,
            );
        }

        $body = ApiHelpers::jsonBody($request);
        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('Provide a multipart `file` or a JSON `url`.');
        }

        return $this->media->importFromUrl(
            $site,
            $url,
            (string) ($body['alt'] ?? ''),
            (bool) ($body['decorative'] ?? false),
        );
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
            'variant_count' => \count($media->variants),
        ];
    }
}
