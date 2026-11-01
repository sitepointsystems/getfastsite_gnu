<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Dto\Site;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/files/action — multipart upload of a downloadable file
 * (PDF, doc, font, archive…). No alt is required (files are not images); the
 * media pipeline enforces the per-type allowlist + size cap. Same two upload
 * shapes as the Media screen: the upload dialog sends ONE file per `ajax=1`
 * trip (JSON out), the no-JS fallback submits `files[]` in one POST.
 */
final class FilesActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly MediaService $media,
        private readonly \Fastsite\Build\Service\PublishTrigger $publish,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $ajax = (string) ($body['ajax'] ?? '') === '1';

        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $ajax ? ApiResponse::error('Site not found.', 404) : $this->dash->notFound();
        }

        $base = '/sites/' . $site->handle . '/files';

        try {
            $action = (string) ($body['action'] ?? '');
            if ($action === 'replace') {
                return $this->replace($site, $base, (int) ($body['id'] ?? 0), Dash::uploads($request)[0] ?? null, $ajax);
            }
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

            $uploads = Dash::uploads($request);
            if ($ajax) {
                return $this->ajaxIngest($site, $uploads[0] ?? null);
            }
            if ($uploads === []) {
                return $this->dash->redirect($base, '', 'Choose a file to upload.');
            }

            return $this->ingestBatch($site, $base, $uploads);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }

    /**
     * Swap the bytes behind an existing id, keeping every `{{ filename }}`
     * embed that points at it. Republishes — the emitted file changes name
     * (immutable caching) and content.
     */
    private function replace(Site $site, string $base, int $id, ?UploadedFileInterface $file, bool $ajax): ResponseInterface
    {
        if ($id <= 0) {
            return $ajax ? ApiResponse::error('Missing file id.', 422) : $this->dash->redirect($base, '', 'Missing file id.');
        }
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $problem = Dash::uploadProblem($file?->getError());

            return $ajax ? ApiResponse::error($problem, 422) : $this->dash->redirect($base, '', $problem);
        }

        set_time_limit(300);
        try {
            $item = $this->media->replace($site, $id, (string) $file->getStream(), (string) $file->getClientFilename());
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $ajax ? ApiResponse::error($e->getMessage(), 422) : $this->dash->redirect($base, '', $e->getMessage());
        }
        $this->publish->trigger($site, 'publish: file #' . $id . ' replaced', 'file-replace');

        $msg = sprintf('Replaced #%d with %s — every page embedding it now serves the new file. Rebuild + publish queued.', $id, $item->originalName);

        return $ajax
            ? ApiResponse::success(['id' => $item->id, 'kind' => $item->kind, 'revision' => $item->revision, 'name' => $item->originalName, 'message' => $msg])
            : $this->dash->redirect($base, $msg);
    }

    /** One file per trip from the upload dialog — JSON, never a redirect. */
    private function ajaxIngest(Site $site, ?UploadedFileInterface $file): ResponseInterface
    {
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return ApiResponse::error(Dash::uploadProblem($file?->getError()), 422);
        }
        set_time_limit(300);
        try {
            $item = $this->media->ingest($site, (string) $file->getStream(), (string) $file->getClientFilename(), '');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
        if ($item->kind !== 'file') {
            return ApiResponse::error('That is a ' . $item->kind . ' — it landed on the Media screen as #' . $item->id . '.', 422);
        }

        return ApiResponse::success([
            'id' => $item->id,
            'kind' => $item->kind,
            'name' => $item->originalName,
            'embed' => '{{ ' . $item->originalName . ' }}',
        ]);
    }

    /**
     * No-JS fallback: the whole `files[]` selection in one POST. One broken
     * file never discards the rest.
     *
     * @param list<UploadedFileInterface> $uploads
     */
    private function ingestBatch(Site $site, string $base, array $uploads): ResponseInterface
    {
        set_time_limit(120 * \count($uploads));
        $done = [];
        $failed = [];
        foreach ($uploads as $file) {
            $name = (string) $file->getClientFilename();
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $failed[] = $name . ': ' . Dash::uploadProblem($file->getError());
                continue;
            }
            try {
                $item = $this->media->ingest($site, (string) $file->getStream(), $name, '');
                if ($item->kind !== 'file') {
                    $failed[] = $name . ' is a ' . $item->kind . ' — it landed on the Media screen as #' . $item->id . '.';
                    continue;
                }
                $done[] = $item;
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $failed[] = $name . ': ' . $e->getMessage();
            }
        }

        $errs = Dash::foldFailures($failed);
        if ($done === []) {
            return $this->dash->redirect($base, '', $errs !== '' ? $errs : 'Choose a file to upload.');
        }
        $msg = \count($done) === 1
            ? 'Uploaded #' . $done[0]->id . ' — embed with {{ ' . $done[0]->originalName . ' }}'
            : 'Uploaded ' . implode(', ', array_map(static fn ($i) => '#' . $i->id, $done)) . '.';

        // redirect() flashes ok OR err, not both — mixed results fold into one line.
        return $this->dash->redirect($base, $errs !== '' ? $msg . ' Failed — ' . $errs : $msg);
    }
}
