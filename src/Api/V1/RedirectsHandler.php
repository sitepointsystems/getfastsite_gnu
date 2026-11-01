<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\RedirectService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirects REST surface (#15), dispatched by method:
 *   GET    /api/v1/sites/{site}/redirects      (sites:read)  — list (+ missing)
 *   POST   /api/v1/sites/{site}/redirects      (sites:write) — create/update
 *   DELETE /api/v1/sites/{site}/redirects/{id} (sites:write) — delete
 *
 * POST body: {from_path, to_path, status?}. `status` may be 301/302/307/308,
 * or 410 to mark the path permanently Gone — in which case `to_path` is
 * omitted (a 410 has no successor). A 410 is answered for real only in
 * direct serve mode; a CDN's _redirects cannot express it.
 */
final class RedirectsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly RedirectService $redirects,
        private readonly PublishTrigger $publish,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $method = strtoupper($request->getMethod());

        if ($method === 'GET') {
            return ApiResponse::success([
                'redirects' => $this->redirects->list($handle),
                'missing' => $this->redirects->listMissing($handle),
            ]);
        }

        if ($method === 'DELETE') {
            $this->redirects->delete($handle, (int) $request->getAttribute('id'));
            $this->publish->trigger($site, 'publish: redirect removed (api)', 'api');

            return ApiResponse::success(['deleted' => true]);
        }

        $body = ApiHelpers::jsonBody($request);
        try {
            $saved = $this->redirects->save($handle, (string) ($body['from_path'] ?? ''), (string) ($body['to_path'] ?? ''), (int) ($body['status'] ?? 301));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
        $this->publish->trigger($site, 'publish: redirect changed (api)', 'api');
        $this->audit->log($access, 'redirect.save', $handle, 'redirect', [], ApiHelpers::clientIp($request));

        return ApiResponse::success(['saved' => $saved, 'redirects' => $this->redirects->list($handle)]);
    }
}
