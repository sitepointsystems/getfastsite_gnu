<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\BuildService;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/preview[/{path}] — the ONE smart preview gateway.
 *
 * Landing on the preview root (`…/preview/`) is a single "Preview" action:
 * it compares a cheap staleness signature (theme version + content count +
 * newest content timestamp) against the `.stamp` written by the last preview
 * build. Fresh → serve the built index immediately. Stale (or never built) →
 * return a "Rebuilding preview…" modal that meta-refreshes into `?rebuild=1`,
 * which runs {@see BuildService::buildPreview}, writes the new stamp, and
 * redirects back to the now-fresh root. So the user clicks one button and
 * either sees the preview or watches it rebuild — no separate build step.
 *
 * The preview tree uses relative internal paths (PreviewRelativizer), so it
 * renders correctly under this `/preview/` base with no deploy. Every served
 * path is realpath-contained under the preview dir.
 */
final class PreviewServeHandler implements RequestHandlerInterface
{
    private const STAMP = '.stamp';

    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
        private readonly BuildService $build,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $base = '/sites/' . $site->handle . '/preview/';
        $previewDir = $this->databases->sitePath($site->handle) . '/preview';

        // Route 31 (no trailing slash) has no {path} var → canonicalize so the
        // browser's base URL ends in '/' and the tree's relative links resolve.
        $rel = $request->getAttribute('path');
        if ($rel === null) {
            return new Response(302, ['Location' => $base, 'Cache-Control' => 'no-store']);
        }
        $rel = (string) $rel;

        // The entry point (the root, or an explicit index.html) is where the
        // smart build-if-stale gate lives. Sub-resources are always served as-is.
        $isEntry = $rel === '' || $rel === 'index.html';

        if ($isEntry) {
            $signature = $this->signature($site->handle, $site->themeVersion);
            $stampPath = $previewDir . '/' . self::STAMP;
            $stamp = is_file($stampPath) ? trim((string) file_get_contents($stampPath)) : '';
            $stale = !is_dir($previewDir) || $stamp !== $signature;

            $rebuild = \array_key_exists('rebuild', $request->getQueryParams());
            if ($rebuild) {
                // The modal navigated here — do the (synchronous) build now, then
                // bounce back to the clean root which is now fresh.
                try {
                    $this->build->buildPreview($site);
                    @file_put_contents($stampPath, $signature);
                } catch (\Throwable $e) {
                    return new Response(
                        200,
                        ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
                        $this->errorPage($site->handle, $e->getMessage()),
                    );
                }

                return new Response(302, ['Location' => $base, 'Cache-Control' => 'no-store']);
            }

            if ($stale) {
                return new Response(
                    200,
                    ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'],
                    $this->rebuildingPage($site->handle),
                );
            }
            // fresh — fall through and serve index.html
        }

        $rootReal = realpath($previewDir);
        if ($rootReal === false) {
            // Fresh signature but no dir shouldn't happen; treat as needs-build.
            return new Response(302, ['Location' => $base . '?rebuild=1', 'Cache-Control' => 'no-store']);
        }

        $serveRel = $rel === '' || str_ends_with($rel, '/') ? $rel . 'index.html' : $rel;
        $target = realpath($previewDir . '/' . $serveRel);
        // Containment: the resolved path must live under the preview root.
        if ($target === false || !str_starts_with($target, $rootReal . \DIRECTORY_SEPARATOR) || !is_file($target)) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not found in preview.\n");
        }

        // Preview is never cached (it changes on each build) and never indexed.
        return new Response(200, [
            'Content-Type' => \Fastsite\Shared\Http\MimeTypes::for($target),
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ], (string) file_get_contents($target));
    }

    /**
     * A cheap fingerprint of everything that changes the built output: the
     * shell/component theme version plus the count and newest timestamp of
     * live content. Any edit bumps one of these, so a mismatch with the last
     * build's stamp means "rebuild".
     */
    private function signature(string $handle, int $themeVersion): string
    {
        try {
            $row = $this->databases->site($handle)->fetchAssociative(
                'SELECT COUNT(*) AS n, COALESCE(MAX(updated_at), \'\') AS latest
                 FROM content WHERE deleted_at IS NULL',
            );
        } catch (\Throwable) {
            $row = false;
        }
        $n = \is_array($row) ? (int) $row['n'] : 0;
        $latest = \is_array($row) ? (string) $row['latest'] : '';

        return $themeVersion . '|' . $n . '|' . $latest;
    }

    private function rebuildingPage(string $handle): string
    {
        $url = '/sites/' . htmlspecialchars($handle, ENT_QUOTES) . '/preview/?rebuild=1';

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="0;url={$url}">
<title>Rebuilding preview…</title>
<style>
  :root{color-scheme:dark}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#070d14;
    color:#e8eef6;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  .modal{max-width:26rem;padding:2.5rem 2rem;text-align:center;background:#0d1622;
    border:1px solid #1c2b3d;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.4)}
  .spin{width:44px;height:44px;margin:0 auto 1.4rem;border-radius:50%;
    border:3px solid #1c2b3d;border-top-color:#2a86ff;animation:s .8s linear infinite}
  @keyframes s{to{transform:rotate(360deg)}}
  h1{margin:0 0 .5rem;font-size:1.15rem}
  p{margin:0;color:#93a4b8;font-size:.9rem}
</style></head><body>
<div class="modal">
  <div class="spin"></div>
  <h1>Rebuilding preview…</h1>
  <p>Changes were detected since the last build. This page refreshes automatically when the preview is ready.</p>
</div>
</body></html>
HTML;
    }

    private function errorPage(string $handle, string $message): string
    {
        $back = '/sites/' . htmlspecialchars($handle, ENT_QUOTES) . '/settings';
        $msg = htmlspecialchars($message, ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Preview build failed</title>
<style>
  :root{color-scheme:dark}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#070d14;
    color:#e8eef6;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  .modal{max-width:32rem;padding:2.5rem 2rem;background:#0d1622;
    border:1px solid #3d1c22;border-radius:14px}
  h1{margin:0 0 .6rem;font-size:1.15rem;color:#ff6b6b}
  pre{white-space:pre-wrap;color:#93a4b8;font-size:.85rem;background:#0a121c;
    padding:.8rem;border-radius:8px;overflow:auto}
  a{color:#2a86ff}
</style></head><body>
<div class="modal">
  <h1>Preview build failed</h1>
  <pre>{$msg}</pre>
  <p><a href="{$back}">← Back to site settings</a></p>
</div>
</body></html>
HTML;
    }
}
