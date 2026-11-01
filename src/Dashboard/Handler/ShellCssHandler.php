<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/shell.css — the site's compiled shell CSS (all
 * shell/assets/*.css, tokens.css first), served to the WYSIWYG editor iframe
 * so the editing surface matches the published page. Behind dashboard auth +
 * the site access check. Not the production CSS path (builds inline it).
 */
final class ShellCssHandler implements RequestHandlerInterface
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

        $assetDir = $this->databases->sitePath($site->handle) . '/shell/assets';
        $files = glob($assetDir . '/*.css') ?: [];
        // tokens.css first — every other sheet reads its custom properties.
        usort($files, static function (string $a, string $b): int {
            return (str_ends_with($a, 'tokens.css') ? 0 : 1) <=> (str_ends_with($b, 'tokens.css') ? 0 : 1)
                ?: strcmp($a, $b);
        });

        $css = '';
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && str_starts_with($real, (string) realpath($assetDir)) && is_file($real)) {
                $css .= (string) file_get_contents($real) . "\n";
            }
        }

        return new Response(200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'private, max-age=60',
            'X-Robots-Tag' => 'noindex',
        ], $css);
    }
}
