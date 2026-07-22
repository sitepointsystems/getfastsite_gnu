<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Nyholm\Psr7\Response;
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
        $sitePath = $this->databases->sitePath($site->handle);
        $root = realpath($sitePath . '/media');
        $target = realpath($sitePath . '/' . ltrim($best->diskPath, '/'));
        if ($root === false || $target === false || !str_starts_with($target, $root . \DIRECTORY_SEPARATOR) || !is_file($target)) {
            return new Response(404);
        }

        return new Response(200, [
            'Content-Type' => self::MIME[$best->format] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ], (string) file_get_contents($target));
    }
}
