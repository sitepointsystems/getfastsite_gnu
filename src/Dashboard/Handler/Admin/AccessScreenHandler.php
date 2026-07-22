<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /admin/access — screen 9: every key, OAuth client, active grant. */
final class AccessScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $engine = $this->databases->engine();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return $this->dash->render($request, 'admin-access.html.twig', [
            'title' => 'Access — fastsite',
            'keys' => $engine->fetchAllAssociative(
                'SELECT k.id, k.name, k.key_id, k.scopes, k.sites, k.last_used_at, k.revoked_at, u.email
                 FROM api_keys k JOIN users u ON u.id = k.user_id ORDER BY k.id DESC LIMIT 100',
            ),
            'clients' => $engine->fetchAllAssociative(
                'SELECT client_id, client_name, is_dynamic, created_at, last_used_at, revoked_at
                 FROM oauth_clients ORDER BY created_at DESC LIMIT 100',
            ),
            'grants' => $engine->fetchAllAssociative(
                "SELECT t.client_id, c.client_name, u.email, COUNT(*) AS tokens, MAX(t.created_at) AS latest
                 FROM oauth_tokens t
                 JOIN oauth_clients c ON c.client_id = t.client_id
                 JOIN users u ON u.id = t.user_id
                 WHERE t.revoked_at IS NULL AND (t.refresh_expires_at IS NULL OR t.refresh_expires_at > ?)
                 GROUP BY t.client_id, c.client_name, u.email ORDER BY latest DESC LIMIT 100",
                [$now],
            ),
        ]);
    }
}
