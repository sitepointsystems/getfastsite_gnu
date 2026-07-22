<?php

declare(strict_types=1);

namespace Fastsite\Webhook;

use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Webhook\Service\SignatureVerifier;
use Fastsite\Webhook\Service\WebhookService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Inbound webhook endpoint: POST /webhooks/{integration}. Verifies the
 * timestamped HMAC, dedups on X-Delivery-Id, records the raw delivery, and
 * enqueues async processing — returns 202 in a few milliseconds so senders
 * never time out and never see mapper work.
 */
final class WebhookHandler implements RequestHandlerInterface
{
    private const SIGNATURE_HEADER = 'X-Fastsite-Signature';
    private const DELIVERY_HEADER = 'X-Delivery-Id';
    private const EVENT_HEADER = 'X-Event';

    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly SignatureVerifier $verifier,
        private readonly JobQueue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = (string) $request->getAttribute('integration');
        $integration = $this->webhooks->findBySlug($slug);
        if ($integration === null || !$integration['active']) {
            return ApiResponse::error('Unknown webhook integration', 404);
        }

        $rawBody = (string) $request->getBody();
        $signature = $request->getHeaderLine(self::SIGNATURE_HEADER);
        if ($signature === '' || !$this->verifier->verify($signature, $rawBody, $integration['secret'])) {
            return ApiResponse::error('Invalid or missing signature', 401);
        }

        $deliveryId = $request->getHeaderLine(self::DELIVERY_HEADER);
        if ($deliveryId === '') {
            return ApiResponse::error('Missing ' . self::DELIVERY_HEADER . ' header', 400);
        }

        $headers = [
            'event' => $request->getHeaderLine(self::EVENT_HEADER),
            'content_type' => $request->getHeaderLine('Content-Type'),
        ];

        $recordId = $this->webhooks->recordDelivery(
            $integration['id'],
            $deliveryId,
            $request->getHeaderLine(self::EVENT_HEADER),
            $headers,
            $rawBody,
        );

        if ($recordId === null) {
            // Replay of an already-seen delivery — acknowledge idempotently.
            return ApiResponse::json(['success' => true, 'data' => ['status' => 'duplicate']], 200);
        }

        $this->queue->enqueue(
            type: 'process_webhook',
            siteHandle: $integration['site_handle'],
            payload: ['delivery_id' => $recordId],
            queue: 'default',
        );

        return ApiResponse::json(['success' => true, 'data' => ['status' => 'accepted', 'delivery' => $recordId]], 202);
    }
}
