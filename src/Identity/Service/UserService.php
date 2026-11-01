<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * User writes. Grows real authentication in M3; M1 only needs the
 * first-admin bootstrap for `bin/fastsite install`.
 */
final class UserService
{
    private const ARGON2_OPTIONS = [
        'memory_cost' => 65536, // 64 MiB
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function hasAdmin(): bool
    {
        return (bool) $this->databases->engine()->fetchOne(
            "SELECT 1 FROM users WHERE role = 'admin' AND deleted_at IS NULL LIMIT 1",
        );
    }

    public function createUser(string $email, string $password, string $name, string $role = 'user'): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
        if (\strlen($password) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters.');
        }
        if (!\in_array($role, ['admin', 'editor', 'user'], true)) {
            throw new \InvalidArgumentException('Role must be admin, editor or user.');
        }

        $db = $this->databases->engine();

        $exists = (bool) $db->fetchOne(
            'SELECT 1 FROM users WHERE email = ? AND deleted_at IS NULL',
            [$email],
        );
        if ($exists) {
            throw new \RuntimeException(sprintf('A user with email %s already exists.', $email));
        }

        $now = $this->clock->iso();
        $db->insert('users', [
            'email' => $email,
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS),
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    /** Self-service change: requires the current password. */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $hash = $this->databases->engine()->fetchOne(
            'SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId],
        );
        if ($hash === false || !password_verify($currentPassword, (string) $hash)) {
            return false;
        }
        $this->setPassword($userId, $newPassword);

        return true;
    }

    /** Admin reset — no current-password proof (admin screen only). */
    public function setPassword(int $userId, string $newPassword): void
    {
        if (\strlen($newPassword) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters.');
        }
        $this->databases->engine()->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS),
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);
    }

    /**
     * Admin edit of the account identity. Email is the login AND what team
     * invites match on, so it is normalized exactly like createUser does.
     */
    public function updateProfile(int $userId, string $email, string $name): void
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email address.');
        }

        $db = $this->databases->engine();
        $taken = (bool) $db->fetchOne(
            'SELECT 1 FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL',
            [$email, $userId],
        );
        if ($taken) {
            throw new \RuntimeException(sprintf('A user with email %s already exists.', $email));
        }

        $db->update('users', [
            'email' => $email,
            'name' => trim($name),
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->databases->engine()->update('users', [
            'is_active' => $active ? 1 : 0,
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);
    }

    /**
     * Hard delete. FK cascades remove the user's memberships, API keys,
     * OAuth grants, remember tokens, credentials AND the teams they own
     * (which cascade their members + invites) — so sites attached to those
     * teams are detached first, or they'd point at a dead team id. Sites
     * themselves are never deleted: an orphaned site stays reachable for
     * admins, who can assign a new owner on /admin/users.
     */
    public function deleteUser(int $userId): void
    {
        $db = $this->databases->engine();
        $db->beginTransaction();
        try {
            $db->executeStatement(
                'UPDATE sites SET team_id = NULL WHERE team_id IN (SELECT id FROM teams WHERE owner_user_id = ?)',
                [$userId],
            );
            $db->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function setRole(int $userId, string $role): void
    {
        if (!\in_array($role, ['admin', 'editor', 'user'], true)) {
            throw new \InvalidArgumentException('Role must be admin, editor or user.');
        }
        $this->databases->engine()->update('users', [
            'role' => $role,
            'updated_at' => $this->clock->iso(),
        ], ['id' => $userId]);
    }
}
