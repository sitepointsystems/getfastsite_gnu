<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Job;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Webhook\Mapper\WebhookMapperRegistry;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Log\LoggerInterface;

/**
 * Runs a recorded webhook delivery through its mapper, then chains a
 * build+publish. Mapper failures mark the delivery failed and rethrow so
 * the queue retries with backoff; a permanently bad payload dead-letters
 * without ever wedging the site.
 */
final class ProcessWebhookHandler implements JobHandler
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly WebhookMapperRegistry $mappers,
        private readonly SiteRepository $sites,
        private readonly PublishTrigger $publish,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'process_webhook';
    }

    public function run(Job $job, JobContext $context): void
    {
        $deliveryId = (int) ($job->payload['delivery_id'] ?? 0);
        $delivery = $this->webhooks->findDelivery($deliveryId);
        if ($delivery === null) {
            $this->logger->warning('process_webhook: delivery vanished', ['delivery' => $deliveryId]);

            return;
        }
        if ($delivery['status'] === 'processed') {
            return; // already done (retry after a mid-crash)
        }

        $site = $this->sites->findByHandle($delivery['site_handle']);
        if ($site === null) {
            $this->webhooks->markDelivery($deliveryId, 'failed', '', 'site no longer exists');

            return;
        }

        $payload = json_decode($delivery['raw_body'], true);
        if (!\is_array($payload)) {
            // Malformed body is not retryable — mark skipped, don't throw.
            $this->webhooks->markDelivery($deliveryId, 'skipped', '', 'payload is not valid JSON');

            return;
        }

        $context->heartbeat();

        try {
            $result = $this->mappers->get($delivery['mapper'])->process(
                $site,
                $payload,
                static fn () => $context->heartbeat(),
            );
        } catch (\InvalidArgumentException $e) {
            // Bad payload shape — not retryable.
            $this->webhooks->markDelivery($deliveryId, 'skipped', '', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            // Transient (image host down, DB busy) — mark failed and rethrow
            // so the queue retries with backoff.
            $this->webhooks->markDelivery($deliveryId, 'failed', '', $e->getMessage());
            throw $e;
        }

        $this->webhooks->markDelivery($deliveryId, 'processed', $result);

        // Coalesced build → publish (translate-first on multilingual sites).
        $this->publish->trigger($site, 'publish: ' . $delivery['mapper'] . ' — ' . $result, 'webhook');

        $this->logger->info('Webhook processed', ['delivery' => $deliveryId, 'mapper' => $delivery['mapper'], 'result' => $result]);
    }
}
