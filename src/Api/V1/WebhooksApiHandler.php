<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Webhook\Mapper\WebhookMapperRegistry;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Webhook management REST surface (#3), dispatched by method:
 *   GET    /api/v1/sites/{site}/webhooks                 (sites:read)  — list + available mappers
 *   POST   /api/v1/sites/{site}/webhooks                 (sites:write) — create {slug, mapper} → endpoint + secret
 *   GET    /api/v1/sites/{site}/webhooks/{id}/deliveries (sites:read)  — recent deliveries
 *   DELETE /api/v1/sites/{site}/webhooks/{id}            (sites:write) — delete
 *
 * Mirrors the MCP tools (list_webhooks/create_webhook/webhook_deliveries) and
 * the dashboard Webhooks screen. Site-scoped: ScopeCheckMiddleware pins the
 * {site} path var to the key's allowed sites.
 */
final class WebhooksApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly WebhookService $webhooks,
        private readonly WebhookMapperRegistry $mappers,
        private readonly AuditLogger $audit,
        private readonly string $appUrl,
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
        $id = $request->getAttribute('id');

        // GET /webhooks/{id}/deliveries
        if ($method === 'GET' && $id !== null) {
            return ApiResponse::success([
                'deliveries' => $this->webhooks->deliveriesForSite((int) $id, $handle),
            ]);
        }

        if ($method === 'GET') {
            return ApiResponse::success([
                'webhooks' => $this->webhooks->listForSite($handle),
                'available_mappers' => $this->mappers->keys(),
            ]);
        }

        if ($method === 'DELETE') {
            $this->webhooks->delete((int) $id, $handle);
            $this->audit->log($access, 'webhook.delete', $handle, 'webhook:' . (int) $id, [], ApiHelpers::clientIp($request));

            return ApiResponse::success(['deleted' => true]);
        }

        // POST — create
        $body = ApiHelpers::jsonBody($request);
        $mapper = (string) ($body['mapper'] ?? '');
        if (!$this->mappers->has($mapper)) {
            return ApiResponse::error('Unknown mapper. Available: ' . implode(', ', $this->mappers->keys()), 422);
        }

        try {
            $result = $this->webhooks->createIntegration(
                (string) ($body['slug'] ?? ''),
                $handle,
                $mapper,
                $access->userId,
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->audit->log($access, 'webhook.create', $handle, 'webhook:' . $result['slug'], ['mapper' => $mapper], ApiHelpers::clientIp($request));

        return ApiResponse::success([
            'id' => $result['id'],
            'endpoint' => \Fastsite\Http\PublicUrl::base($request, $this->appUrl) . '/webhooks/' . $result['slug'],
            'secret' => $result['secret'],
        ], 201);
    }
}
