<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/media/action — multipart upload or SSRF-guarded URL
 * import. alt required for images unless decorative.
 */
final class MediaActionHandler implements RequestHandlerInterface
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

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $base = '/sites/' . $site->handle . '/media';
        $alt = trim((string) ($body['alt'] ?? ''));
        $decorative = (string) ($body['decorative'] ?? '') === '1';

        try {
            $action = (string) ($body['action'] ?? '');
            if ($action !== '') {
                $id = (int) ($body['id'] ?? 0);
                switch ($action) {
                    case 'edit':
                        $this->media->updateMeta($site, $id, $alt, isset($body['decorative']) ? $decorative : null);

                        return $this->dash->redirect($base, 'Updated #' . $id . '.');
                    case 'delete':
                        $this->media->softDelete($site, $id);

                        return $this->dash->redirect($base, 'Moved #' . $id . ' to trash.');
                    case 'restore':
                        $this->media->restore($site, $id);

                        return $this->dash->redirect($base . '?trash=1', 'Restored #' . $id . '.');
                    case 'purge':
                        $this->media->purge($site, $id);

                        return $this->dash->redirect($base . '?trash=1', 'Permanently deleted #' . $id . '.');
                    default:
                        return $this->dash->redirect($base, '', 'Unknown action.');
                }
            }

            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if ($file !== null && $file->getError() === UPLOAD_ERR_OK) {
                $item = $this->media->ingest(
                    $site,
                    (string) $file->getStream(),
                    (string) $file->getClientFilename(),
                    $alt,
                    $decorative,
                );
            } elseif (($url = trim((string) ($body['url'] ?? ''))) !== '') {
                $item = $this->media->importFromUrl($site, $url, $alt, $decorative);
            } else {
                return $this->dash->redirect($base, '', 'Choose a file or paste a URL.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }

        return $this->dash->redirect($base, 'Uploaded #' . $item->id . ' — embed with <img data-media-id="' . $item->id . '" alt="…">');
    }
}
