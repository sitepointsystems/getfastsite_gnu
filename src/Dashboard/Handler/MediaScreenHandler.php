<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\SiteLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /sites/{site}/media — screen 3: media library + upload/import. */
final class MediaScreenHandler implements RequestHandlerInterface
{
    /**
     * Grid or list, remembered per browser. A cookie rather than a user
     * column: it is a viewing preference, not data, and it has to survive
     * following a link that carries no `?view=` — which rules out the query
     * string alone. Set server-side so the no-JS path works too.
     */
    public const VIEW_COOKIE = 'fs_media_view';

    private const VIEWS = ['grid', 'list'];

    public function __construct(
        private readonly Dash $dash,
        private readonly MediaService $media,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $trash = isset($query['trash']);

        // An explicit ?view= wins and is remembered; otherwise the cookie; a
        // junk value in either falls back to grid rather than erroring.
        $asked = (string) ($query['view'] ?? '');
        $remember = \in_array($asked, self::VIEWS, true);
        $view = $remember ? $asked : (string) ($request->getCookieParams()[self::VIEW_COOKIE] ?? 'grid');
        if (!\in_array($view, self::VIEWS, true)) {
            $view = 'grid';
        }

        // The media library is images + video; downloadable files live on the
        // Files screen (kind='file').
        $result = $trash
            ? $this->media->listTrashed($site->handle, $page, 30, ['image', 'video'])
            : $this->media->list($site->handle, $page, 30, ['image', 'video']);

        // ShortPixel controls: only rendered when a key is active for the site
        // (its own or the global fallback), so a keyless install shows nothing.
        $optimizerOn = $this->media->optimizerConfiguredFor($site);

        // ?crop=N opens the reframe panel for one image, mirroring the
        // Components screen's ?component= convention. Server-rendered rather
        // than a JS-only dialog so the numeric fields work with JS off —
        // dragging a rectangle cannot be made to, but typing four numbers can.
        $cropItem = null;
        if (($cropId = (int) ($query['crop'] ?? 0)) > 0) {
            $candidate = $this->media->findWithVariants($site->handle, $cropId);
            if ($candidate !== null && $candidate->kind === 'image' && $candidate->status === 'ready') {
                $cropItem = $candidate;
            }
        }

        $response = $this->dash->render($request, 'media.html.twig', [
            'links' => $this->links($site, $result['items']),
            'has_origin' => $site->canonicalBaseUrl() !== '',
            'view' => $view,
            'crop_item' => $cropItem,
            'title' => $site->name . ' media — fastsite',
            'site' => $site,
            'items' => $result['items'],
            'total' => $result['total'],
            'trash' => $trash,
            'page' => $page,
            'pages' => (int) ceil($result['total'] / 30),
            'optimizer_on' => $optimizerOn,
            'unoptimized' => $optimizerOn ? $this->media->countUnoptimized($site->handle) : 0,
        ]);

        return $remember
            ? $response->withAddedHeader('Set-Cookie', self::VIEW_COOKIE . '=' . $view . '; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly')
            : $response;
    }

    /**
     * The public URL of each item, and whether that URL currently resolves.
     *
     * `live` is read off the BUILT TREE rather than inferred, because media
     * ships REFERENCED-ONLY: an image no page embeds is pruned from dist, so
     * the URL of a freshly uploaded file is a 404 until something uses it —
     * and that is exactly the file someone wants a URL for. Handing over a
     * link that looks fine and 404s is worse than saying so.
     *
     * Keyed by media id; items with no shipped rendition (video, or an upload
     * still processing) are absent rather than carrying a made-up URL.
     *
     * @param list<\Fastsite\Media\Dto\Media> $items
     * @return array<int, array{url: string, path: string, live: bool}>
     */
    private function links(\Fastsite\Site\Dto\Site $site, array $items): array
    {
        if ($site->canonicalBaseUrl() === '') {
            return []; // no origin yet — there is no public URL to offer
        }

        $buildPath = $this->databases->sitePath($site->handle) . '/build';
        $out = [];
        foreach ($items as $m) {
            $path = SiteLinks::mediaPath($m);
            if ($path === null) {
                continue;
            }
            $out[$m->id] = [
                'url' => SiteLinks::absolute($site, $path),
                'path' => $path,
                'live' => is_file($buildPath . $path),
            ];
        }

        return $out;
    }
}
