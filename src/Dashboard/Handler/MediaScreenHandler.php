<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /sites/{site}/media — screen 3: media library + upload/import. */
final class MediaScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly MediaService $media,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $trash = isset($query['trash']);
        // The media library is images + video; downloadable files live on the
        // Files screen (kind='file').
        $result = $trash
            ? $this->media->listTrashed($site->handle, $page, 30, ['image', 'video'])
            : $this->media->list($site->handle, $page, 30, ['image', 'video']);

        return $this->dash->render($request, 'media.html.twig', [
            'title' => $site->name . ' media — fastsite',
            'site' => $site,
            'items' => $result['items'],
            'total' => $result['total'],
            'trash' => $trash,
            'page' => $page,
            'pages' => (int) ceil($result['total'] / 30),
        ]);
    }
}
