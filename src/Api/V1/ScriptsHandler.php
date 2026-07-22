<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\SiteScriptService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Header/footer scripts REST surface (#13):
 *   GET    /api/v1/sites/{site}/scripts        (sites:read) — list
 *   POST   /api/v1/sites/{site}/scripts        (sites:write) — create/update
 *   DELETE /api/v1/sites/{site}/scripts/{id}   (sites:write) — delete
 * One handler dispatched by method (the id route reuses it).
 */
final class ScriptsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteScriptService $scripts,
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
            return ApiResponse::success(['scripts' => array_map(self::toArray(...), $this->scripts->list($handle))]);
        }

        if ($method === 'DELETE') {
            $this->scripts->delete($handle, (int) $request->getAttribute('id'));
            $this->publish->trigger($site, 'publish: site script removed (api)', 'api');
            $this->audit->log($access, 'script.delete', $handle, 'script:' . $request->getAttribute('id'), [], ApiHelpers::clientIp($request));

            return ApiResponse::success(['deleted' => true]);
        }

        // POST — create/update.
        try {
            $script = $this->scripts->save($handle, ApiHelpers::jsonBody($request));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
        $this->publish->trigger($site, 'publish: site script changed (api)', 'api');
        $this->audit->log($access, 'script.save', $handle, 'script:' . $script->id, [], ApiHelpers::clientIp($request));

        return ApiResponse::success(self::toArray($script));
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(\Fastsite\Site\Dto\SiteScript $s): array
    {
        return ['id' => $s->id, 'label' => $s->label, 'location' => $s->location, 'strategy' => $s->strategy, 'priority' => $s->priority, 'code' => $s->code, 'enabled' => $s->enabled];
    }
}
