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
 * signature, dedups on the delivery id, records the raw delivery, and
 * enqueues async processing — responds in a few milliseconds so senders
 * never time out and never see mapper work.
 *
 * Two auth schemes, keyed on the SAME stored integration secret:
 *  - fastsite:  X-Fastsite-Signature: t=<unix>,v1=<hmac("<t>.<body>")>
 *  - AutoSEO:   X-AutoSEO-Signature: <hex hmac(body)> and/or
 *               Authorization: Bearer <token> where the token IS the secret
 *               (AutoSEO signs with its own endpoint token — store that token
 *               as the integration secret to accept its deliveries).
 */
final class WebhookHandler implements RequestHandlerInterface
{
    private const SIGNATURE_HEADER = 'X-Fastsite-Signature';
    private const DELIVERY_HEADER = 'X-Delivery-Id';
    private const EVENT_HEADER = 'X-Event';

    private const AUTOSEO_SIGNATURE_HEADER = 'X-AutoSEO-Signature';
    private const AUTOSEO_DELIVERY_HEADER = 'X-AutoSEO-Delivery';
    private const AUTOSEO_EVENT_HEADER = 'X-AutoSEO-Event';

    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly SignatureVerifier $verifier,
        private readonly JobQueue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Sender-side endpoint checkers (AutoSEO among them) probe with a
        // GET/HEAD and expect 200. Always answer ready — a liveness ping
        // reveals nothing, not even whether the slug exists.
        $method = strtoupper($request->getMethod());
        if ($method === 'GET' || $method === 'HEAD') {
            return ApiResponse::json(['success' => true, 'data' => ['status' => 'ready', 'hint' => 'POST signed deliveries to this URL']], 200);
        }

        $slug = (string) $request->getAttribute('integration');
        $integration = $this->webhooks->findBySlug($slug);
        if ($integration === null || !$integration['active']) {
            return ApiResponse::error('Unknown webhook integration', 404);
        }

        $rawBody = (string) $request->getBody();
        $signature = $request->getHeaderLine(self::SIGNATURE_HEADER);
        $fastsiteScheme = $signature !== '';
        if ($fastsiteScheme) {
            if (!$this->verifier->verify($signature, $rawBody, $integration['secret'])) {
                return ApiResponse::error('Invalid signature', 401);
            }
        } else {
            $autoseoSig = $request->getHeaderLine(self::AUTOSEO_SIGNATURE_HEADER);
            $ok = ($autoseoSig !== '' && $this->verifier->verifyPlain($autoseoSig, $rawBody, $integration['secret']))
                || $this->verifier->verifyBearer($request->getHeaderLine('Authorization'), $integration['secret']);
            if (!$ok) {
                return ApiResponse::error('Invalid or missing signature', 401);
            }
        }

        $event = $request->getHeaderLine(self::EVENT_HEADER)
            ?: $request->getHeaderLine(self::AUTOSEO_EVENT_HEADER);

        // AutoSEO's endpoint-verification ping: a "test" event it expects a
        // plain 200 for. Nothing to record or process.
        if ($this->isTestEvent($event, $rawBody)) {
            return ApiResponse::json(['success' => true, 'data' => ['status' => 'test-ok']], 200);
        }

        $deliveryId = $request->getHeaderLine(self::DELIVERY_HEADER)
            ?: $request->getHeaderLine(self::AUTOSEO_DELIVERY_HEADER);
        if ($deliveryId === '') {
            return ApiResponse::error('Missing ' . self::DELIVERY_HEADER . ' header', 400);
        }

        $headers = [
            'event' => $event,
            'content_type' => $request->getHeaderLine('Content-Type'),
        ];

        $recordId = $this->webhooks->recordDelivery(
            $integration['id'],
            $deliveryId,
            $event,
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

        // AutoSEO treats anything but 200 as a failed endpoint; fastsite-scheme
        // senders get the honest 202 (processing is async).
        $status = $fastsiteScheme ? 202 : 200;

        return ApiResponse::json(['success' => true, 'data' => ['status' => 'accepted', 'delivery' => $recordId]], $status);
    }

    private function isTestEvent(string $event, string $rawBody): bool
    {
        if ($event === 'test') {
            return true;
        }
        if ($event !== '') {
            return false;
        }
        $decoded = json_decode($rawBody, true);

        return \is_array($decoded) && ($decoded['event'] ?? null) === 'test';
    }
}
