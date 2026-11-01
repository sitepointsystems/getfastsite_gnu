<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Service\SiteService;
use Fastsite\Webhook\Mapper\WebhookMapperRegistry;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /admin/sites/action — create site, create webhook integration
 * (secret rendered ONCE from this response), replay a delivery.
 */
final class SitesActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SitesScreenHandler $screen,
        private readonly SiteService $sites,
        private readonly WebhookService $webhooks,
        private readonly WebhookMapperRegistry $mappers,
        private readonly JobQueue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create-site':
                    $site = $this->sites->create(
                        (string) ($body['handle'] ?? ''),
                        trim((string) ($body['name'] ?? '')) ?: (string) ($body['handle'] ?? ''),
                        trim((string) ($body['url'] ?? '')),
                        trim((string) ($body['repo'] ?? '')),
                        trim((string) ($body['locale'] ?? '')) ?: 'en',
                        (int) $this->dash->user($request)['id'],
                    );

                    return $this->dash->redirect('/admin/sites', 'Site "' . $site->handle . '" created — shell scaffolded, DB migrated.');

                case 'create-webhook':
                    $mapper = (string) ($body['mapper'] ?? 'autoseo');
                    if (!$this->mappers->has($mapper)) {
                        return $this->dash->redirect('/admin/sites', '', 'Unknown mapper: ' . $mapper);
                    }
                    $result = $this->webhooks->createIntegration(
                        (string) ($body['slug'] ?? ''),
                        (string) ($body['site'] ?? ''),
                        $mapper,
                    );

                    return $this->dash->render($request, 'admin-sites.html.twig', $this->screen->context($request, [
                        'new_secret' => ['slug' => $result['slug'], 'secret' => $result['secret']],
                    ]));

                case 'replay-delivery':
                    $deliveryId = (int) ($body['delivery_id'] ?? 0);
                    $delivery = $this->webhooks->findDelivery($deliveryId);
                    if ($delivery === null) {
                        return $this->dash->redirect('/admin/sites', '', 'Delivery not found.');
                    }
                    $this->queue->enqueue('process_webhook', $delivery['site_handle'], ['delivery_id' => $deliveryId], 'default');

                    return $this->dash->redirect('/admin/sites', 'Delivery #' . $deliveryId . ' requeued.');

                default:
                    return $this->dash->redirect('/admin/sites', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/admin/sites', '', $e->getMessage());
        }
    }
}
