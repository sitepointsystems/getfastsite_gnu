<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

use Fastsite\Site\Dto\Site;

/**
 * Turns an inbound webhook payload into content. Each integration names the
 * mapper it uses; the process_webhook job resolves it from the registry.
 */
interface WebhookMapper
{
    /** Stable key stored on the integration row. */
    public static function key(): string;

    /**
     * Ingests the payload into the site (create/update content, adopt
     * images). Returns a short human-readable result for the delivery log.
     * Throwing marks the delivery failed (and retries via the job).
     *
     * @param array<string, mixed> $payload
     * @param \Closure(): void $heartbeat call during slow work (image fetches)
     *                          so the queue watchdog doesn't reclaim the job
     */
    public function process(Site $site, array $payload, \Closure $heartbeat): string;
}
