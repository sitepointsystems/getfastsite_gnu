<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /api/v1/sites/{site}/components/{handle}
 * Body JSON: { type: "html"|"react", source_code, css?, variables_schema? }
 * React components compile at upload; a compile error is a 422.
 */
final class ComponentUpsertHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ComponentService $components,
        private readonly \Fastsite\Component\Service\ComponentHookService $hooks,
        private readonly \Fastsite\Build\Service\PublishTrigger $publish,
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
        $componentHandle = (string) $request->getAttribute('handle');

        try {
            $component = $this->components->upsert($site, $componentHandle, ApiHelpers::jsonBody($request));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->audit->log($access, 'component.upsert', $handle, 'component:' . $component->handle, ['type' => $component->type], ApiHelpers::clientIp($request));

        // A referenced component appears on pages whose content rows never
        // move — hook-injected pages have no row at all, and an embedded
        // placeholder doesn't change when the component behind it does —
        // republish now or the live site keeps rendering the old build.
        $referenced = $this->hooks->referencing($handle, $component->handle)
            || $this->components->referencedByContent($site, $component->handle);
        if ($referenced) {
            $this->publish->trigger($site, 'publish: referenced component changed (api)', 'api');
        }

        return ApiResponse::json(['success' => true, 'data' => [
            'handle' => $component->handle,
            'type' => $component->type,
            'status' => $component->status,
            'verbatim' => $component->verbatim,
            'warnings' => $component->warnings,
            'republished' => $referenced,
        ]], 201);
    }
}
