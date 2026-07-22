<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

use Fastsite\Component\Service\ComponentService;
use Fastsite\Site\Dto\Site;

/**
 * Built-in system webhook: {handle, type, source_code, css?} → an upserted
 * component (html/react).
 */
final class UpsertComponentMapper implements WebhookMapper
{
    public function __construct(private readonly ComponentService $components)
    {
    }

    public static function key(): string
    {
        return 'upsert_component';
    }

    public function process(Site $site, array $payload, \Closure $heartbeat): string
    {
        $handle = (string) ($payload['handle'] ?? '');
        if ($handle === '') {
            throw new \InvalidArgumentException('upsert_component requires a "handle".');
        }
        $component = $this->components->upsert($site, $handle, $payload);

        return 'upsert_component → ' . $component->handle . ' (' . $component->status . ')';
    }
}
