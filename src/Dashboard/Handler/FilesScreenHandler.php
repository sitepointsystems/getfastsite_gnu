<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/files — the downloadable-files library (kind='file':
 * PDFs, docs, fonts, archives…). Files are emitted to /files/{id}/ at build
 * and embedded in content with <a data-file-id="N">label</a>.
 */
final class FilesScreenHandler implements RequestHandlerInterface
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
        $result = $trash
            ? $this->media->listTrashed($site->handle, $page, 30, ['file'])
            : $this->media->list($site->handle, $page, 30, ['file']);

        return $this->dash->render($request, 'files.html.twig', [
            'title' => $site->name . ' files — fastsite',
            'site' => $site,
            'items' => $result['items'],
            'total' => $result['total'],
            'trash' => $trash,
            'page' => $page,
            'pages' => (int) ceil($result['total'] / 30),
        ]);
    }
}
