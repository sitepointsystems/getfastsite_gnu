<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/files/action — multipart upload of a downloadable file
 * (PDF, doc, font, archive…). No alt is required (files are not images); the
 * media pipeline enforces the per-type allowlist + size cap.
 */
final class FilesActionHandler implements RequestHandlerInterface
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

        $base = '/sites/' . $site->handle . '/files';
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        try {
            $action = (string) ($body['action'] ?? '');
            if ($action !== '') {
                $id = (int) ($body['id'] ?? 0);
                switch ($action) {
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
            if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
                return $this->dash->redirect($base, '', 'Choose a file to upload.');
            }
            $item = $this->media->ingest(
                $site,
                (string) $file->getStream(),
                (string) $file->getClientFilename(),
                '',
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }

        if ($item->kind !== 'file') {
            return $this->dash->redirect($base, '', 'That is a ' . $item->kind . ' — upload it on the Media screen instead.');
        }

        return $this->dash->redirect($base, 'Uploaded #' . $item->id . ' — embed with {{ ' . $item->originalName . ' }}');
    }
}
