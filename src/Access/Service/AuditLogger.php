<?php

declare(strict_types=1);

namespace Fastsite\Access\Service;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Log\LoggerInterface;

final class AuditLogger
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Best-effort: a transient audit-write failure must NEVER turn an
     * already-committed mutation into a 500 (whose retry would duplicate it).
     *
     * @param array<string, mixed> $detail
     */
    public function log(?AccessContext $access, string $action, ?string $siteHandle = null, string $target = '', array $detail = [], string $ip = ''): void
    {
        try {
            $this->databases->engine()->insert('audit_log', [
                'actor_type' => $access?->actorType ?? 'system',
                'user_id' => $access?->userId,
                'api_key_id' => $access?->apiKeyId,
                'site_handle' => $siteHandle,
                'action' => $action,
                'target' => $target,
                'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
                'ip' => $ip,
                'created_at' => $this->clock->iso(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
