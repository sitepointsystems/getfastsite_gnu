<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Dashboard\Dash;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /admin/users/action — create, toggle active, reset password, grant/
 * revoke site membership. Deactivation kills sessions, remember-me tokens,
 * and API-key cache entries immediately.
 */
final class UsersActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly UserService $users,
        private readonly SessionService $sessions,
        private readonly RememberTokenService $remember,
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
        $admin = $this->dash->user($request);
        $engine = $this->databases->engine();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create':
                    $id = $this->users->createUser(
                        (string) ($body['email'] ?? ''),
                        (string) ($body['password'] ?? ''),
                        trim((string) ($body['name'] ?? '')),
                        ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
                    );

                    return $this->dash->redirect('/admin/users', 'User #' . $id . ' created.');

                case 'toggle':
                    $userId = (int) ($body['user_id'] ?? 0);
                    if ($userId === $admin['id']) {
                        return $this->dash->redirect('/admin/users', '', 'You cannot deactivate yourself.');
                    }
                    $current = $engine->fetchOne('SELECT is_active FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
                    if ($current === false) {
                        return $this->dash->redirect('/admin/users', '', 'No such user.');
                    }
                    $activate = (int) $current !== 1;
                    $this->users->setActive($userId, $activate);
                    if (!$activate) {
                        $this->sessions->logoutAllForUser($userId);
                        $this->remember->revokeAll($userId);
                        $this->keys->invalidateUser($userId);
                    }

                    return $this->dash->redirect('/admin/users', $activate ? 'User activated.' : 'User deactivated — sessions and key cache cleared.');

                case 'reset-password':
                    $userId = (int) ($body['user_id'] ?? 0);
                    $this->users->setPassword($userId, (string) ($body['password'] ?? ''));
                    $this->sessions->logoutAllForUser($userId);
                    $this->remember->revokeAll($userId);

                    return $this->dash->redirect('/admin/users', 'Password reset — the user was signed out everywhere.');

                case 'grant-site':
                    $userId = (int) ($body['user_id'] ?? 0);
                    $handle = (string) ($body['site'] ?? '');
                    $siteId = $engine->fetchOne('SELECT id FROM sites WHERE handle = ?', [$handle]);
                    if ($siteId === false) {
                        return $this->dash->redirect('/admin/users', '', 'No such site.');
                    }
                    $exists = $engine->fetchOne('SELECT 1 FROM site_users WHERE site_id = ? AND user_id = ?', [(int) $siteId, $userId]);
                    if ($exists === false) {
                        $engine->insert('site_users', [
                            'site_id' => (int) $siteId,
                            'user_id' => $userId,
                            'role' => ($body['site_role'] ?? 'editor') === 'owner' ? 'owner' : 'editor',
                            'created_at' => $this->clock->iso(),
                        ]);
                    }

                    return $this->dash->redirect('/admin/users', 'Access granted.');

                case 'revoke-site':
                    $userId = (int) ($body['user_id'] ?? 0);
                    $handle = (string) ($body['site'] ?? '');
                    $engine->executeStatement(
                        'DELETE FROM site_users WHERE user_id = ? AND site_id = (SELECT id FROM sites WHERE handle = ?)',
                        [$userId, $handle],
                    );

                    return $this->dash->redirect('/admin/users', 'Access revoked.');

                default:
                    return $this->dash->redirect('/admin/users', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/admin/users', '', $e->getMessage());
        }
    }
}
