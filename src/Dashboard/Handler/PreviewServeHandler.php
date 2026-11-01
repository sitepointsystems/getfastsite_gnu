<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\BuildService;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\BuildHeaders;
use Fastsite\Site\Service\SiteScriptService;
use GuzzleHttp\Psr7\Response;
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
 *
 * Responses carry the build's own `_headers` (mirrored into the preview tree
 * like any other non-HTML file), so preview enforces the SAME scripts-mode CSP
 * and security headers the CDN will. Without that, preview is not a test
 * surface: anything the policy forbids — an Alpine-style runtime that compiles
 * expressions with `new Function()`, an embed that evals — works here and dies
 * on the live domain, and the author has no signal until someone opens a
 * console in production.
 */
final class PreviewServeHandler implements RequestHandlerInterface
{
    private const STAMP = '.stamp';

    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
        private readonly BuildService $build,
        private readonly BuildHeaders $buildHeaders,
        private readonly SiteScriptService $scripts,
        private readonly \Fastsite\Component\Service\ComponentHookService $componentHooks,
        private readonly \Fastsite\Component\Service\ComponentService $components,
        private readonly \Fastsite\Form\Service\FormService $forms,
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

        // EVERY page request — the root or a deep link straight at one page —
        // runs the build-if-stale gate; only sub-resources (css/js/images,
        // fetched by an already-current page) are served as-is. The Content
        // screen's per-row Preview button deep-links here, and a "Preview"
        // that silently serves the tree from before your last edit is worse
        // than no preview at all.
        $isEntry = $rel === '' || str_ends_with($rel, '/') || str_ends_with($rel, '.html');
        // Where to come back to after a rebuild: the page that was asked for,
        // not the site root.
        $self = $base . self::encodePath($rel);

        if ($isEntry) {
            $signature = $this->signature($site);
            $stampPath = $previewDir . '/' . self::STAMP;
            $stamp = is_file($stampPath) ? trim((string) file_get_contents($stampPath)) : '';
            $stale = !is_dir($previewDir) || $stamp !== $signature;

            $rebuild = \array_key_exists('rebuild', $request->getQueryParams());
            if ($rebuild) {
                // The modal navigated here — do the (synchronous) build now, then
                // bounce back to the clean URL which is now fresh.
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

                return new Response(302, ['Location' => $self, 'Cache-Control' => 'no-store']);
            }

            if ($stale) {
                return new Response(
                    200,
                    ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'],
                    $this->rebuildingPage($self . '?rebuild=1'),
                );
            }
            // fresh — fall through and serve the page
        }

        $rootReal = realpath($previewDir);
        if ($rootReal === false) {
            // Fresh signature but no dir shouldn't happen; treat as needs-build.
            return new Response(302, ['Location' => $self . '?rebuild=1', 'Cache-Control' => 'no-store']);
        }

        $serveRel = $rel === '' || str_ends_with($rel, '/') ? $rel . 'index.html' : $rel;
        $target = realpath($previewDir . '/' . $serveRel);
        // Containment: the resolved path must live under the preview root.
        if ($target === false || !str_starts_with($target, $rootReal . \DIRECTORY_SEPARATOR) || !is_file($target)) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not found in preview.\n");
        }

        // The deploy contract first, so preview enforces the same CSP the CDN
        // will. Matched on the path WITHIN the tree — `_headers` patterns are
        // written for the domain root, not this `/sites/{handle}/preview/` base.
        $headers = $this->buildHeaders->for($rootReal, '/' . ltrim($serveRel, '/'));

        // Then preview's own truths, which override the build: it is never
        // cached (it changes on each build) and never indexed — `_headers`
        // marks /assets/* and /media/* immutable, which would pin a stale
        // preview in the browser across rebuilds.
        $headers['Content-Type'] = \Fastsite\Shared\Http\MimeTypes::for($target);
        $headers['Cache-Control'] = 'no-store';
        $headers['X-Robots-Tag'] = 'noindex';

        return new Response(200, $headers, (string) file_get_contents($target));
    }

    /**
     * A cheap fingerprint of everything that changes the built output: the
     * shell/component theme version, the count and newest timestamp of live
     * content, and the site's header/footer scripts. Any edit bumps one of
     * these, so a mismatch with the last build's stamp means "rebuild".
     *
     * The scripts part is a hash of what the build actually injects, not a
     * count+timestamp: they were missing from the signature entirely, so
     * saving a script rebuilt-and-published in the background but the preview
     * kept serving the tree from before it — the only way to see (and so to
     * trust) a tag was to make a throwaway shell edit and bump theme_version.
     */
    private function signature(\Fastsite\Site\Dto\Site $site): string
    {
        $handle = $site->handle;
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

        // Was a hash of the rendered BYTES, which was right while a script
        // either applied everywhere or nowhere. Targeting broke that: changing
        // a script's target changes which pages carry it and changes not one
        // byte of the bundle, so preview would have kept serving the old tree.
        // The service's own signature covers the targeting columns.
        $scripts = $this->scripts->signature($handle);

        // Media has the same shape as component hooks: it changes built pages
        // with no content timestamp moving. REPLACING an image is the sharp
        // case — the pages emit a new URL and new bytes while every content
        // row is untouched, so without this, preview (the obvious place to go
        // and check the replace worked) would serve the pre-replace tree.
        // updated_at moves on replace, alt edits, optimization and deletes.
        try {
            $mediaRow = $this->databases->site($handle)->fetchAssociative(
                'SELECT COUNT(*) AS n, COALESCE(MAX(updated_at), \'\') AS latest, COALESCE(SUM(revision), 0) AS revs
                 FROM media WHERE deleted_at IS NULL',
            );
        } catch (\Throwable) {
            $mediaRow = false;
        }
        $media = \is_array($mediaRow)
            ? $mediaRow['n'] . ':' . $mediaRow['latest'] . ':' . $mediaRow['revs']
            : '';
        // Categories, the same shape again: a rename moves archives and post
        // URLs without touching a content timestamp.
        try {
            $categoryRow = $this->databases->site($handle)->fetchAssociative(
                'SELECT COUNT(*) AS n, COALESCE(MAX(updated_at), \'\') AS latest FROM categories',
            );
        } catch (\Throwable) {
            $categoryRow = false;
        }
        $media .= \is_array($categoryRow) ? '|cat:' . $categoryRow['n'] . ':' . $categoryRow['latest'] : '';

        // Every build-affecting site-row setting, hashed from the SAME list
        // changeNeedsRebuild uses — this supersedes the old cta_tracking-only
        // fragment, which is why flipping the title suffix, the default meta
        // description, the canonical host, the share image, the structured-data
        // settings or the serve mode used to republish in the background while
        // preview went on serving the pre-change tree (the documented
        // staleness symptom). Component hooks (rules + hooked components'
        // bundle hashes) have the same shape but live outside the site row:
        // they change built pages with no content timestamp moving.
        // ALL components' bundle hashes + verbatim flags, not just hooked
        // ones: editing a content-embedded component (or toggling verbatim)
        // changes built pages while every content timestamp stays put — the
        // same staleness shape as media replace, and previously only the
        // hook-referenced subset was covered.
        // Forms are the same shape again: the definition lives in the site db,
        // its markup is generated at build, and editing it (a new field, a
        // changed button label, a different success page) moves no content
        // timestamp at all. Without this, preview — the obvious place to go
        // and check a form edit — would keep serving the pre-edit markup, and
        // the only way to see the change would be a throwaway shell edit to
        // bump theme_version. That is the fourth instance of this bug class.
        return $site->themeVersion . '|' . $n . '|' . $latest . '|' . $scripts
            . '|' . \Fastsite\Site\Service\SiteService::outputSignature($site)
            . '|hk' . $this->componentHooks->signature($handle)
            . '|cp' . $this->components->signature($handle)
            . '|md' . $media
            . '|fm' . $this->forms->signature($handle);
    }

    /**
     * Percent-encode a preview-relative path for use in a Location header or
     * a meta-refresh URL, keeping the '/' separators intact. The path is
     * attacker-influenced (it is whatever the browser asked for), so it must
     * never reach a header or HTML raw.
     */
    private static function encodePath(string $rel): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $rel)));
    }

    private function rebuildingPage(string $target): string
    {
        $url = htmlspecialchars($target, ENT_QUOTES);

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
