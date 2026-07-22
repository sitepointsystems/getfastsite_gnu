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

        return ApiResponse::json(['success' => true, 'data' => [
            'handle' => $component->handle,
            'type' => $component->type,
            'status' => $component->status,
            'warnings' => $component->warnings,
        ]], 201);
    }
}
