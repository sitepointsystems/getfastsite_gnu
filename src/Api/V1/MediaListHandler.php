<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Media\Dto\Media;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MediaListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly MediaRepository $media,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        if ($this->sites->findByHandle($handle) === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        // Optional ?kind=image|video|file filter (comma-separated allowed).
        $kinds = null;
        if (isset($query['kind']) && trim((string) $query['kind']) !== '') {
            $requested = array_map('trim', explode(',', (string) $query['kind']));
            $kinds = array_values(array_intersect($requested, ['image', 'video', 'file']));
            if ($kinds === []) {
                $kinds = null;
            }
        }

        $result = $this->media->list($handle, $page, $perPage, $kinds);

        return ApiResponse::paginated(
            array_map(static fn (Media $m): array => [
                'id' => $m->id,
                'kind' => $m->kind,
                'original_name' => $m->originalName,
                'mime' => $m->mime,
                'bytes' => $m->bytes,
                'width' => $m->width,
                'height' => $m->height,
                'alt' => $m->alt,
                'status' => $m->status,
            ], $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }
}
