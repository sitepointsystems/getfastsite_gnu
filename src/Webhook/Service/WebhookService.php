<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Webhook integration registry + delivery log. Secrets are stored encrypted
 * (TokenCipher) because they must be re-revealable to configure the sender.
 */
final class WebhookService
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly TokenCipher $cipher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{id: int, slug: string, secret: string}
     */
    public function createIntegration(string $slug, string $siteHandle, string $mapper, ?int $userId = null): array
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid integration slug.');
        }

        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $enc = $this->cipher->encrypt($secret);
        if ($enc === null) {
            throw new \RuntimeException('APP_SECRET is not configured — cannot encrypt the webhook secret.');
        }

        $db = $this->databases->engine();
        $now = $this->clock->iso();
        $db->insert('webhook_integrations', [
            'slug' => $slug,
            'site_handle' => $siteHandle,
            'mapper' => $mapper,
            'secret_enc' => $enc,
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $db->lastInsertId(), 'slug' => $slug, 'secret' => $secret];
    }

    /**
     * A site's integrations with recent delivery counts (no secret).
     *
     * @return list<array<string, mixed>>
     */
    public function listForSite(string $siteHandle): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT i.id, i.slug, i.mapper, i.active, i.created_at,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.integration_id = i.id) AS deliveries
             FROM webhook_integrations i WHERE i.site_handle = ? ORDER BY i.id DESC',
            [$siteHandle],
        );
    }

    /**
     * One integration incl. its decrypted secret (for the reveal panel), or
     * null if it isn't on this site / can't be decrypted.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForSite(int $id, string $siteHandle): ?array
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM webhook_integrations WHERE id = ? AND site_handle = ?',
            [$id, $siteHandle],
        );
        if ($row === false) {
            return null;
        }
        $secret = $this->cipher->decrypt((string) $row['secret_enc']);

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'mapper' => (string) $row['mapper'],
            'active' => (int) $row['active'] === 1,
            'secret' => $secret ?? '',
        ];
    }

    /**
     * Recent deliveries for a site's integration.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveriesForSite(int $integrationId, string $siteHandle, int $limit = 20): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT d.id, d.event, d.status, d.error, d.created_at
             FROM webhook_deliveries d JOIN webhook_integrations i ON i.id = d.integration_id
             WHERE d.integration_id = ? AND i.site_handle = ? ORDER BY d.id DESC LIMIT ?',
            [$integrationId, $siteHandle, $limit],
        );
    }

    public function setActive(int $id, string $siteHandle, bool $active): void
    {
        $this->databases->engine()->executeStatement(
            'UPDATE webhook_integrations SET active = ?, updated_at = ? WHERE id = ? AND site_handle = ?',
            [$active ? 1 : 0, $this->clock->iso(), $id, $siteHandle],
        );
    }

    public function delete(int $id, string $siteHandle): void
    {
        $db = $this->databases->engine();
        $db->executeStatement('DELETE FROM webhook_deliveries WHERE integration_id = ?', [$id]);
        $db->executeStatement('DELETE FROM webhook_integrations WHERE id = ? AND site_handle = ?', [$id, $siteHandle]);
    }

    /**
     * @return array{id: int, slug: string, site_handle: string, mapper: string, secret: string, active: bool}|null
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM webhook_integrations WHERE slug = ?',
            [$slug],
        );
        if ($row === false) {
            return null;
        }

        $secret = $this->cipher->decrypt((string) $row['secret_enc']);
        if ($secret === null || $secret === '') {
            // Fail CLOSED: a secret that can't be decrypted (APP_SECRET
            // rotated/changed, DB moved) must never degrade HMAC auth into
            // an empty-key accept-anything gate. The integration is
            // unusable until the secret is re-set.
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'site_handle' => (string) $row['site_handle'],
            'mapper' => (string) $row['mapper'],
            'secret' => $secret,
            'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * Records a delivery, enforcing the replay guard. Returns the new
     * delivery id, or null when this delivery_uuid was already seen.
     *
     * @param array<string, mixed> $headers
     */
    public function recordDelivery(int $integrationId, string $deliveryUuid, string $event, array $headers, string $rawBody): ?int
    {
        $db = $this->databases->engine();
        try {
            $db->insert('webhook_deliveries', [
                'integration_id' => $integrationId,
                'delivery_uuid' => $deliveryUuid,
                'event' => $event,
                'headers_json' => json_encode($headers, JSON_THROW_ON_ERROR),
                'raw_body' => $rawBody,
                'status' => 'received',
                'created_at' => $this->clock->iso(),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return null; // replay — already recorded
        }

        return (int) $db->lastInsertId();
    }

    /**
     * @return array{id: int, integration_id: int, raw_body: string, status: string, mapper: string, site_handle: string}|null
     */
    public function findDelivery(int $deliveryId): ?array
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT d.*, i.mapper, i.site_handle
             FROM webhook_deliveries d JOIN webhook_integrations i ON i.id = d.integration_id
             WHERE d.id = ?',
            [$deliveryId],
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'integration_id' => (int) $row['integration_id'],
            'raw_body' => (string) $row['raw_body'],
            'status' => (string) $row['status'],
            'mapper' => (string) $row['mapper'],
            'site_handle' => (string) $row['site_handle'],
        ];
    }

    public function markDelivery(int $deliveryId, string $status, string $result = '', ?string $error = null): void
    {
        $this->databases->engine()->update('webhook_deliveries', [
            'status' => $status,
            'result' => $result,
            'error' => $error,
            'processed_at' => $this->clock->iso(),
        ], ['id' => $deliveryId]);
    }
}
