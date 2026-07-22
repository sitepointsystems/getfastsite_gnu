<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\RedirectService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /collect/{site}/404 — fire-and-forget 404 beacon (#15C). An edge
 * collector (Cloudflare Worker or the static 404 page) posts the missing path
 * so the dashboard can suggest a redirect. Unauthenticated + rate-limited;
 * always answers 204 so a bad beacon never surfaces to a visitor.
 */
final class Collect404Handler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly RedirectService $redirects,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site !== null) {
            $params = $request->getQueryParams();
            $body = $request->getParsedBody();
            $body = \is_array($body) ? $body : [];
            $path = (string) ($params['path'] ?? $body['path'] ?? '');
            $referrer = (string) ($params['ref'] ?? $body['referrer'] ?? '');
            if ($path !== '') {
                try {
                    $this->redirects->recordMissing($handle, $path, $referrer);
                } catch (\Throwable) {
                    // A beacon must never error.
                }
            }
        }

        return new Response(204, ['Cache-Control' => 'no-store']);
    }
}
