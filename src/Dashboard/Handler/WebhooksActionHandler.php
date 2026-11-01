<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Http\PublicUrl;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /sites/{site}/webhooks/action — create / toggle / delete / replay. */
final class WebhooksActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly WebhookService $webhooks,
        private readonly JobQueue $queue,
        private readonly string $appUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $base = '/sites/' . $site->handle . '/webhooks';

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'webhook-create':
                    $result = $this->webhooks->createIntegration(
                        (string) ($body['slug'] ?? ''),
                        $site->handle,
                        (string) ($body['mapper'] ?? ''),
                        (int) $user['id'],
                    );

                    // Reveal the fresh secret via the re-revealable panel (the
                    // secret itself never travels in the redirect query).
                    return $this->dash->redirect(
                        $base . '?secret_for=' . $result['id'],
                        'Webhook created. Point your sender at ' . PublicUrl::base($request, $this->appUrl) . '/webhooks/' . $result['slug'] . '.',
                    );

                case 'webhook-secret':
                    // Sender-owned credential (e.g. AutoSEO's endpoint token):
                    // store it as the integration secret so its bearer/HMAC
                    // verify against it.
                    $id = (int) ($body['id'] ?? 0);
                    $this->webhooks->setSecret($id, $site->handle, (string) ($body['secret'] ?? ''));

                    return $this->dash->redirect($base . '?secret_for=' . $id, 'Webhook secret updated.');

                case 'webhook-toggle':
                    $this->webhooks->setActive((int) ($body['id'] ?? 0), $site->handle, (string) ($body['active'] ?? '') === '1');

                    return $this->dash->redirect($base, 'Webhook updated.');

                case 'webhook-delete':
                    $this->webhooks->delete((int) ($body['id'] ?? 0), $site->handle);

                    return $this->dash->redirect($base, 'Webhook deleted.');

                case 'webhook-replay':
                    $deliveryId = (int) ($body['delivery_id'] ?? 0);
                    $delivery = $this->webhooks->findDelivery($deliveryId);
                    if ($delivery === null || $delivery['site_handle'] !== $site->handle) {
                        return $this->dash->redirect($base, '', 'Delivery not found.');
                    }
                    $this->queue->enqueue('process_webhook', $site->handle, ['delivery_id' => $deliveryId], 'default');

                    return $this->dash->redirect($base . '?deliveries=' . $delivery['integration_id'], 'Delivery re-queued.');

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }
}
