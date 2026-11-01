<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Component\Dto\Component;
use Fastsite\Component\Service\ComponentHookService;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Components REST read/delete surface (upsert stays in ComponentUpsertHandler):
 *   GET    /api/v1/sites/{site}/components           (sites:read) — list (no source)
 *   GET    /api/v1/sites/{site}/components/{handle}  (sites:read) — one, FULL source back
 *   DELETE /api/v1/sites/{site}/components/{handle}  (components:write) — delete
 * One handler dispatched by method, same shape as ComponentHooksHandler.
 * A delete of a component that hooks or content still reference republishes,
 * so the live site drops the render instead of serving it forever.
 */
final class ComponentsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ComponentService $components,
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
        $componentHandle = (string) ($request->getAttribute('handle') ?? '');
        $method = strtoupper($request->getMethod());

        if ($method === 'GET' && $componentHandle === '') {
            return ApiResponse::success([
                'components' => array_map(fn (Component $c): array => self::toArray($c, false), $this->components->list($site)),
            ]);
        }

        if ($method === 'GET') {
            $component = $this->components->get($site, $componentHandle);
            if ($component === null) {
                return ApiResponse::error('Unknown component: ' . $componentHandle, 404);
            }

            return ApiResponse::success(self::toArray($component, true));
        }

        // DELETE.
        if ($this->components->get($site, $componentHandle) === null) {
            return ApiResponse::error('Unknown component: ' . $componentHandle, 404);
        }
        $referenced = $this->hooks->referencing($handle, $componentHandle)
            || $this->components->referencedByContent($site, $componentHandle);
        $this->components->delete($site, $componentHandle);
        $this->audit->log($access, 'component.delete', $handle, 'component:' . $componentHandle, [], ApiHelpers::clientIp($request));
        if ($referenced) {
            $this->publish->trigger($site, 'publish: referenced component removed (api)', 'api');
        }

        return ApiResponse::success(['deleted' => true, 'republished' => $referenced]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Component $c, bool $full): array
    {
        $out = [
            'handle' => $c->handle,
            'type' => $c->type,
            'status' => $c->status,
            'verbatim' => $c->verbatim,
            'warnings' => $c->warnings,
            'error' => $c->error,
        ];
        if ($full) {
            $out['source_code'] = $c->sourceCode;
            $out['css'] = $c->css;
            $out['variables_schema'] = json_decode($c->variablesSchema, true) ?: new \stdClass();
            $out['use_in_body'] = '<div data-fs-component="' . $c->handle . '" data-values="{}"></div>';
        }

        return $out;
    }
}
