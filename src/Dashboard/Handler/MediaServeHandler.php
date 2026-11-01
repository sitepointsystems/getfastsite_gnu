<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/media/{id}/thumb — serve the smallest responsive image
 * variant from disk so the dashboard can show real thumbnails (media variants
 * live on the engine volume, not a public URL until a build). Behind dashboard
 * auth + the site access check; realpath-contained under the site's media dir.
 * Non-image media (files/video with no responsive variant) → 404 (the screen
 * shows a type icon instead).
 */
final class MediaServeHandler implements RequestHandlerInterface
{
    private const MIME = ['webp' => 'image/webp', 'avif' => 'image/avif', 'png' => 'image/png', 'jpg' => 'image/jpeg'];

    public function __construct(
        private readonly Dash $dash,
        private readonly MediaRepository $media,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        // findAnyById so thumbnails still show in the Trash view.
        $item = $this->media->findAnyById($site->handle, (int) $request->getAttribute('id'));
        if ($item === null) {
            return new Response(404);
        }

        // ?original=1 serves the stored ORIGINAL rather than a variant.
        //
        // The crop editor needs this and cannot use a variant: variants have
        // the stored crop already applied, so drawing a rect on one would
        // measure a fraction of the CROPPED image while the value stored is a
        // fraction of the original — every re-crop would compound. It is the
        // full-size file, which is why nothing but the editor asks for it.
        if ((string) ($request->getQueryParams()['original'] ?? '') === '1' && $item->kind === 'image') {
            $path = $this->contained($site->handle, $item->diskPath);

            return $path === null ? new Response(404) : new Response(200, [
                'Content-Type' => $item->mime,
                'Cache-Control' => 'private, max-age=60',
                'X-Robots-Tag' => 'noindex',
            ], (string) file_get_contents($path));
        }

        // Smallest responsive variant, format preference webp→jpg→png→avif.
        $best = null;
        foreach ($item->variants as $variant) {
            if ($variant->purpose !== 'responsive') {
                continue;
            }
            if ($best === null || $variant->width < $best->width) {
                $best = $variant;
            }
        }
        if ($best === null) {
            return new Response(404);
        }

        // Variant disk_path is stored RELATIVE to the site dir (e.g.
        // "media/variants/12/hero-480.webp"), so resolve it against the site
        // path — realpath($best->diskPath) alone would resolve against the
        // process CWD and always fail (→ 404 → the 🖼 placeholder).
        $target = $this->contained($site->handle, $best->diskPath);
        if ($target === null) {
            return new Response(404);
        }

        return new Response(200, [
            'Content-Type' => self::MIME[$best->format] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ], (string) file_get_contents($target));
    }

    /**
     * Resolve a stored relative disk path, refusing anything that escapes the
     * site's own media directory.
     *
     * Variant and original disk_paths are stored RELATIVE to the site dir
     * (e.g. "media/variants/12/hero-480.webp"), so realpath() on the stored
     * value alone would resolve against the process CWD and always fail —
     * which presented as a 404 and the 🖼 placeholder.
     */
    private function contained(string $handle, string $relative): ?string
    {
        $sitePath = $this->databases->sitePath($handle);
        $root = realpath($sitePath . '/media');
        $target = realpath(str_starts_with($relative, $sitePath) ? $relative : $sitePath . '/' . ltrim($relative, '/'));
        if ($root === false || $target === false || !str_starts_with($target, $root . \DIRECTORY_SEPARATOR) || !is_file($target)) {
            return null;
        }

        return $target;
    }
}
