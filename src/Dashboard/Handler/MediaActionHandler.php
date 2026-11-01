<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/media/action — multipart upload or SSRF-guarded URL
 * import (empty alt is auto-generated), plus ShortPixel re-optimization:
 * `optimize` runs one image inline (same latency as an upload with the key
 * set), `optimize-all` queues the batch job. Uploads come in two shapes:
 * the upload dialog sends ONE file per `ajax=1` trip (JSON out — the
 * per-file loop lives in the client, like the SEO-fix dialog, so each
 * file's error lands on its own row), and the no-JS fallback submits the
 * whole `files[]` selection in one POST, looped here.
 */
final class MediaActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly MediaService $media,
        private readonly JobQueue $queue,
        private readonly PublishTrigger $publish,
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

        $base = '/sites/' . $site->handle . '/media';
        $alt = trim((string) ($body['alt'] ?? ''));
        $decorative = (string) ($body['decorative'] ?? '') === '1';

        try {
            $action = (string) ($body['action'] ?? '');
            if ($action === 'replace') {
                return $this->replace($site, $base, (int) ($body['id'] ?? 0), Dash::uploads($request)[0] ?? null, $ajax);
            }
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
                    case 'optimize':
                        // Inline, like ingest-time optimization — the click
                        // waits on ShortPixel exactly as an upload would.
                        $result = $this->media->optimizeExisting($site, $id);
                        if (!$result['changed']) {
                            return $this->dash->redirect($base, 'No further savings for #' . $id . ' — marked as optimized.');
                        }
                        $this->publish->trigger($site, 'publish: image #' . $id . ' optimized', 'media-optimize');

                        return $this->dash->redirect($base, sprintf('Optimized #%d — saved %s KB. Rebuild + publish queued.', $id, number_format($result['saved'] / 1024, 1)));
                    case 'optimize-all':
                        $count = $this->media->countUnoptimized($site->handle);
                        if ($count === 0) {
                            return $this->dash->redirect($base, 'All images are already optimized.');
                        }
                        // Coalesced per site; promoteNow kicks a dedupe-absorbed
                        // twin out of retry backoff (same reasoning as
                        // retry-translations) so the click always acts now.
                        $uuid = $this->queue->enqueue('optimize_media', $site->handle, ['trigger' => 'dashboard'], 'default', 'optimize:' . $site->handle);
                        $this->queue->promoteNow($uuid);

                        return $this->dash->redirect($base, 'Optimization of ' . $count . ' image(s) queued — each is marked as it finishes (refresh to follow), then the site republishes.');
                    case 'crop':
                        // Non-destructive: the original is untouched, so this
                        // is re-runnable forever. It still republishes,
                        // because the emitted filenames move (immutable
                        // caching) and every page embedding the id follows.
                        $clear = (string) ($body['clear'] ?? '') === '1';
                        $rect = null;
                        if (!$clear) {
                            $rect = [
                                'x' => (float) ($body['crop_x'] ?? 0),
                                'y' => (float) ($body['crop_y'] ?? 0),
                                'w' => (float) ($body['crop_w'] ?? 0),
                                'h' => (float) ($body['crop_h'] ?? 0),
                            ];
                            // A resize with no crop is a legitimate request:
                            // "just make it 1200 wide".
                            if ($rect['w'] <= 0.0 || $rect['h'] <= 0.0) {
                                $rect = null;
                            }
                        }
                        $width = $clear ? null : (int) ($body['resize_width'] ?? 0);
                        $item = $this->media->transform($site, $id, $rect, $width);
                        $this->publish->trigger($site, 'publish: image #' . $id . ' reframed', 'media-crop');

                        return $this->dash->redirect($base, $clear
                            ? sprintf('Cleared the crop on #%d — back to the full image (%d×%d). Rebuild + publish queued.', $id, (int) $item->width, (int) $item->height)
                            : sprintf('#%d is now %d×%d — every page embedding it follows. Rebuild + publish queued.', $id, (int) $item->width, (int) $item->height));
                    case 'bulk-delete':
                    case 'bulk-restore':
                    case 'bulk-purge':
                    case 'bulk-optimize':
                        return $this->bulk($site, $base, $action, $body['ids'] ?? null);
                    default:
                        return $this->dash->redirect($base, '', 'Unknown action.');
                }
            }

            $uploads = Dash::uploads($request);
            if ($ajax) {
                return $this->ajaxIngest($site, $uploads[0] ?? null, $alt, $decorative);
            }

            if ($uploads !== []) {
                return $this->ingestBatch($site, $base, $uploads, $alt, $decorative);
            }
            if (($url = trim((string) ($body['url'] ?? ''))) !== '') {
                $item = $this->media->importFromUrl($site, $url, $alt, $decorative);

                return $this->dash->redirect($base, 'Uploaded #' . $item->id . ' — embed with <img data-media-id="' . $item->id . '" alt="…">');
            }

            return $this->dash->redirect($base, '', 'Choose a file or paste a URL.');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }

    /**
     * The selection actions. One loop, because delete/restore/purge differ
     * only in which service call they make.
     *
     * One failing id never discards the rest (same contract as the upload
     * batch) — an item someone else purged in another tab must not take the
     * other nineteen down with it.
     *
     * Bulk optimize is the exception: it does NOT run inline. Twenty-five
     * ShortPixel round-trips would outlive nginx's read timeout, so it hands
     * the selection to the `optimize_media` job, which already accepts
     * `payload.ids` and chunks them.
     *
     * @param mixed $raw the posted ids[] — untrusted shape
     */
    private function bulk(Site $site, string $base, string $action, mixed $raw): ResponseInterface
    {
        $back = str_contains($action, 'restore') || str_contains($action, 'purge') ? $base . '?trash=1' : $base;

        $ids = [];
        foreach (\is_array($raw) ? $raw : [] as $value) {
            if (\is_scalar($value) && (int) $value > 0) {
                $ids[(int) $value] = true; // dedupe: a doubled checkbox must not act twice
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            // The bulk bar renders with no JS too, so an empty submit is a
            // normal thing to happen rather than a tampering signal.
            return $this->dash->redirect($back, '', 'Select at least one item first.');
        }

        if ($action === 'bulk-optimize') {
            // Deliberately NO dedupe key. `optimize:{site}` would absorb this
            // into a pending "Optimize all" twin whose payload carries a
            // different id list, silently replacing the selection with that
            // job's. SiteLock still serialises the runs, and optimized_at
            // means no image is ever paid for twice.
            $uuid = $this->queue->enqueue('optimize_media', $site->handle, ['trigger' => 'dashboard-bulk', 'ids' => $ids], 'default');
            $this->queue->promoteNow($uuid);

            return $this->dash->redirect($base, 'Optimization of ' . \count($ids) . ' selected image(s) queued — each is marked as it finishes (refresh to follow), then the site republishes.');
        }

        $done = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'bulk-delete' => $this->media->softDelete($site, $id),
                    'bulk-restore' => $this->media->restore($site, $id),
                    default => $this->media->purge($site, $id),
                };
                ++$done;
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $failed[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        $verb = match ($action) {
            'bulk-delete' => 'Moved ' . $done . ' item(s) to trash.',
            'bulk-restore' => 'Restored ' . $done . ' item(s).',
            default => 'Permanently deleted ' . $done . ' item(s).',
        };
        $errs = Dash::foldFailures($failed);
        if ($done === 0) {
            return $this->dash->redirect($back, '', $errs !== '' ? $errs : 'Nothing changed.');
        }

        return $this->dash->redirect($back, $errs !== '' ? $verb . ' Failed — ' . $errs : $verb);
    }

    /**
     * Swap the bytes behind an existing id, keeping every embed that points at
     * it. Republishes, because the pages that reference it now render a
     * different file (and a different URL for it).
     */
    private function replace(Site $site, string $base, int $id, ?UploadedFileInterface $file, bool $ajax): ResponseInterface
    {
        if ($id <= 0) {
            return $ajax ? ApiResponse::error('Missing media id.', 422) : $this->dash->redirect($base, '', 'Missing media id.');
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
        $this->publish->trigger($site, 'publish: media #' . $id . ' replaced', 'media-replace');

        $msg = sprintf('Replaced #%d with %s — every page embedding it now shows the new file. Rebuild + publish queued.', $id, $item->originalName);

        return $ajax
            ? ApiResponse::success(['id' => $item->id, 'kind' => $item->kind, 'revision' => $item->revision, 'alt' => $item->alt, 'message' => $msg])
            : $this->dash->redirect($base, $msg);
    }

    /**
     * One file per trip from the upload dialog — JSON, never a redirect, so
     * this file's failure reason lands on its own row while the client
     * carries on with the rest of the batch.
     */
    private function ajaxIngest(Site $site, ?UploadedFileInterface $file, string $alt, bool $decorative): ResponseInterface
    {
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return ApiResponse::error(Dash::uploadProblem($file?->getError()), 422);
        }
        // ShortPixel + sharp on a big image can outlive a default PHP limit.
        // (nginx's fastcgi_read_timeout is still shorter — the dialog maps a
        // 504 to an honest "may still finish" row rather than an auth hint.)
        set_time_limit(300);
        try {
            $item = $this->media->ingest($site, (string) $file->getStream(), (string) $file->getClientFilename(), $alt, $decorative);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
        if ($item->kind === 'file') {
            // The Media screen lists images+video only — a success here would
            // show a checkmark for an item that then "vanishes".
            return ApiResponse::error('That is a file, not media — it landed on the Files screen as #' . $item->id . '. Embed with {{ ' . $item->originalName . ' }}.', 422);
        }

        return ApiResponse::success([
            'id' => $item->id,
            'kind' => $item->kind,
            'alt' => $item->alt,
            'embed' => '<img data-media-id="' . $item->id . '" alt="' . htmlspecialchars($item->alt, ENT_QUOTES) . '">',
        ]);
    }

    /**
     * No-JS fallback: the whole selection in one POST. One typed alt cannot
     * describe several images, so a batch ignores the field and lets auto-alt
     * fill each (the dialog never sends alt with a batch either) — but ONE
     * decorative checkbox can describe several images, so that flag applies
     * to every file. One broken file never discards the rest.
     *
     * @param list<UploadedFileInterface> $uploads
     */
    private function ingestBatch(Site $site, string $base, array $uploads, string $alt, bool $decorative): ResponseInterface
    {
        $batch = \count($uploads) > 1;
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
                $item = $this->media->ingest($site, (string) $file->getStream(), $name, $batch ? '' : $alt, $decorative);
                if ($item->kind === 'file') {
                    $failed[] = $name . ' is a file, not media — it landed on the Files screen as #' . $item->id . '.';
                    continue;
                }
                $done[] = $item;
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $failed[] = $name . ': ' . $e->getMessage();
            }
        }

        $errs = Dash::foldFailures($failed);
        if ($done === []) {
            return $this->dash->redirect($base, '', $errs !== '' ? $errs : 'Choose a file or paste a URL.');
        }
        $msg = \count($done) === 1 && !$batch
            ? 'Uploaded #' . $done[0]->id . ' — embed with <img data-media-id="' . $done[0]->id . '" alt="…">'
            : 'Uploaded ' . implode(', ', array_map(static fn ($i) => '#' . $i->id, $done)) . '.';

        // redirect() flashes ok OR err, not both — mixed results fold into one line.
        return $this->dash->redirect($base, $errs !== '' ? $msg . ' Failed — ' . $errs : $msg);
    }
}
