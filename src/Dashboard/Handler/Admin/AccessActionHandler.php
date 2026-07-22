<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /admin/access/action — revoke any API key, disable an OAuth client
 * (kills all its tokens), revoke one user↔client grant.
 */
final class AccessActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ApiKeyService $keys,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $engine = $this->databases->engine();
        $now = $this->clock->iso();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'revoke-key':
                    $this->keys->revoke((int) ($body['key_id'] ?? 0));

                    return $this->dash->redirect('/admin/access', 'Key revoked (effective immediately).');

                case 'revoke-client':
                    $clientId = (string) ($body['client_id'] ?? '');
                    $engine->executeStatement(
                        'UPDATE oauth_clients SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                        [$now, $clientId],
                    );
                    $engine->executeStatement(
                        'UPDATE oauth_tokens SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                        [$now, $clientId],
                    );

                    return $this->dash->redirect('/admin/access', 'Client disabled — every token it held is revoked.');

                case 'revoke-grant':
                    $clientId = (string) ($body['client_id'] ?? '');
                    $email = mb_strtolower((string) ($body['email'] ?? ''));
                    $engine->executeStatement(
                        'UPDATE oauth_tokens SET revoked_at = ?
                         WHERE client_id = ? AND revoked_at IS NULL
                           AND user_id = (SELECT id FROM users WHERE email = ? AND deleted_at IS NULL)',
                        [$now, $clientId, $email],
                    );

                    return $this->dash->redirect('/admin/access', 'Grant revoked.');

                case 'delete-key':
                    // Permanent — only for an already-revoked key (the service guards this).
                    $this->keys->delete((int) ($body['key_id'] ?? 0));

                    return $this->dash->redirect('/admin/access', 'Key permanently deleted.');

                case 'delete-client':
                    // Only a disabled (revoked) client may be purged; drop its token history too.
                    $clientId = (string) ($body['client_id'] ?? '');
                    $disabled = (bool) $engine->fetchOne(
                        'SELECT 1 FROM oauth_clients WHERE client_id = ? AND revoked_at IS NOT NULL',
                        [$clientId],
                    );
                    if (!$disabled) {
                        return $this->dash->redirect('/admin/access', '', 'Disable the client before deleting it permanently.');
                    }
                    $engine->executeStatement('DELETE FROM oauth_tokens WHERE client_id = ?', [$clientId]);
                    $engine->executeStatement('DELETE FROM oauth_clients WHERE client_id = ?', [$clientId]);

                    return $this->dash->redirect('/admin/access', 'OAuth client permanently deleted.');

                case 'delete-grant':
                    // Purge every token (active + revoked) for this user↔client pair.
                    $clientId = (string) ($body['client_id'] ?? '');
                    $email = mb_strtolower((string) ($body['email'] ?? ''));
                    $engine->executeStatement(
                        'DELETE FROM oauth_tokens
                         WHERE client_id = ?
                           AND user_id = (SELECT id FROM users WHERE email = ? AND deleted_at IS NULL)',
                        [$clientId, $email],
                    );

                    return $this->dash->redirect('/admin/access', 'Grant permanently deleted.');

                default:
                    return $this->dash->redirect('/admin/access', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/admin/access', '', $e->getMessage());
        }
    }
}
