<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Component\Dto\ComponentHook;
use Fastsite\Component\Service\ComponentHookService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Component hooks REST surface (build-time CTA injection rules):
 *   GET    /api/v1/sites/{site}/component-hooks        (sites:read) — list
 *   POST   /api/v1/sites/{site}/component-hooks        (components:write) — create/update (pass id)
 *   DELETE /api/v1/sites/{site}/component-hooks/{id}   (components:write) — delete
 * One handler dispatched by method (the id route reuses it). Writes republish.
 */
final class ComponentHooksHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ComponentHookService $hooks,
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
            return ApiResponse::success(['hooks' => array_map(self::toArray(...), $this->hooks->list($handle))]);
        }

        if ($method === 'DELETE') {
            $this->hooks->delete($handle, (int) $request->getAttribute('id'));
            $this->publish->trigger($site, 'publish: component hook removed (api)', 'api');
            $this->audit->log($access, 'component_hook.delete', $handle, 'hook:' . $request->getAttribute('id'), [], ApiHelpers::clientIp($request));

            return ApiResponse::success(['deleted' => true]);
        }

        // POST — create/update.
        try {
            $hook = $this->hooks->save($handle, ApiHelpers::jsonBody($request));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
        $this->publish->trigger($site, 'publish: component hook changed (api)', 'api');
        $this->audit->log($access, 'component_hook.save', $handle, 'hook:' . $hook->id, [], ApiHelpers::clientIp($request));

        return ApiResponse::success(self::toArray($hook));
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(ComponentHook $h): array
    {
        return [
            'id' => $h->id,
            'label' => $h->label,
            'component_handle' => $h->componentHandle,
            'values' => $h->values === [] ? new \stdClass() : $h->values,
            'position' => $h->position,
            'paragraph' => $h->paragraph,
            'target' => $h->target,
            'include_slugs' => $h->includeSlugs,
            'exclude_slugs' => $h->excludeSlugs,
            'priority' => $h->priority,
            'enabled' => $h->enabled,
        ];
    }
}
