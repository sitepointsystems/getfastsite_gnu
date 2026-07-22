<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/card.png — the site's homepage screenshot thumbnail
 * (generated at preview time by BuildService::captureCard). 404 when a site
 * has never been previewed / no browser was available; the overview card then
 * shows a lettered fallback. Behind dashboard auth + the site access check.
 */
final class SiteCardServeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $root = realpath($this->databases->sitePath($site->handle));
        $target = realpath($this->databases->sitePath($site->handle) . '/card.png');
        if ($root === false || $target === false || !str_starts_with($target, $root . \DIRECTORY_SEPARATOR) || !is_file($target)) {
            return new Response(404);
        }

        return new Response(200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=120',
            'X-Robots-Tag' => 'noindex',
        ], (string) file_get_contents($target));
    }
}
