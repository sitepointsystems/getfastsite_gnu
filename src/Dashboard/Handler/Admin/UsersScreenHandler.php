<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /admin/users — screen 6. */
final class UsersScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $engine = $this->databases->engine();

        $users = $engine->fetchAllAssociative(
            'SELECT id, email, name, role, is_active, last_login_at, created_at,
                    (totp_seed_enc IS NOT NULL) AS totp_on
             FROM users WHERE deleted_at IS NULL ORDER BY id',
        );

        $memberships = [];
        foreach ($engine->fetchAllAssociative(
            'SELECT su.user_id, s.handle, su.role FROM site_users su JOIN sites s ON s.id = su.site_id',
        ) as $row) {
            $memberships[(int) $row['user_id']][] = (string) $row['handle'] . ' (' . (string) $row['role'] . ')';
        }

        return $this->dash->render($request, 'admin-users.html.twig', [
            'title' => 'Users — fastsite',
            'users' => $users,
            'memberships' => $memberships,
            'site_handles' => array_map(static fn ($s) => $s->handle, $this->dash->sitesFor($this->dash->user($request))),
        ]);
    }
}
