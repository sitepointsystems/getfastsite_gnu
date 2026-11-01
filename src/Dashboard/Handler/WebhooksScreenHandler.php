<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Http\PublicUrl;
use Fastsite\Webhook\Mapper\WebhookMapperRegistry;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/webhooks — per-site webhook management (#3): create an
 * integration pointing at a built-in mapper, reveal its signing secret, watch
 * deliveries. Site-member scoped (not admin-only) via Dash::siteFor.
 */
final class WebhooksScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly WebhookService $webhooks,
        private readonly WebhookMapperRegistry $mappers,
        private readonly string $appUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();
        $reveal = isset($query['secret_for']) ? $this->webhooks->findByIdForSite((int) $query['secret_for'], $site->handle) : null;
        $deliveries = isset($query['deliveries']) ? $this->webhooks->deliveriesForSite((int) $query['deliveries'], $site->handle) : [];

        return $this->dash->render($request, 'webhooks.html.twig', [
            'title' => $site->name . ' webhooks — fastsite',
            'site' => $site,
            // The copy-pasteable URL a sender must call — absolute, on the
            // engine's public origin (senders live outside this deployment).
            'webhook_base' => PublicUrl::base($request, $this->appUrl) . '/webhooks/',
            'integrations' => $this->webhooks->listForSite($site->handle),
            'mapper_keys' => $this->mappers->keys(),
            'reveal' => $reveal,
            'deliveries' => $deliveries,
            'deliveries_for' => (int) ($query['deliveries'] ?? 0),
        ]);
    }
}
