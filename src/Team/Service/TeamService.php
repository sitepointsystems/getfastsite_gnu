<?php

declare(strict_types=1);

namespace Fastsite\Team\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Teams (#2): create a team, invite people by email (tokenized link), and let
 * them join. Site access flows from team membership — see Dash::sitesFor and
 * McpTools::canAccess, which both union team_members with site_users.
 */
final class TeamService
{
    private const INVITE_TTL_DAYS = 14;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function create(int $ownerUserId, string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A team name is required.');
        }
        $db = $this->databases->engine();
        $now = $this->clock->iso();
        $db->insert('teams', ['name' => mb_substr($name, 0, 200), 'owner_user_id' => $ownerUserId, 'created_at' => $now]);
        $teamId = (int) $db->lastInsertId();
        $db->insert('team_members', ['team_id' => $teamId, 'user_id' => $ownerUserId, 'role' => 'owner', 'created_at' => $now]);

        return $teamId;
    }

    /**
     * Teams the user owns or belongs to.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT t.id, t.name, t.owner_user_id, tm.role,
                    (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) AS members
             FROM teams t JOIN team_members tm ON tm.team_id = t.id
             WHERE tm.user_id = ? ORDER BY t.id DESC',
            [$userId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(int $teamId): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT u.id, u.email, u.name, tm.role, tm.created_at
             FROM team_members tm JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ? ORDER BY tm.role, u.email',
            [$teamId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingInvites(int $teamId): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT id, email, expires_at FROM team_invites WHERE team_id = ? AND accepted_at IS NULL ORDER BY id DESC',
            [$teamId],
        );
    }

    public function isOwner(int $teamId, int $userId): bool
    {
        return (bool) $this->databases->engine()->fetchOne(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND role = 'owner'",
            [$teamId, $userId],
        );
    }

    public function isMember(int $teamId, int $userId): bool
    {
        return (bool) $this->databases->engine()->fetchOne(
            'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId],
        );
    }

    /** Create an invite and return its token (share the link /invite/{token}). */
    public function invite(int $teamId, string $email, int $byUserId): string
    {
        if (!$this->isOwner($teamId, $byUserId)) {
            throw new \RuntimeException('Only a team owner can invite members.');
        }
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required.');
        }
        $token = bin2hex(random_bytes(24));
        $expires = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()->modify('+' . self::INVITE_TTL_DAYS . ' days')->getTimestamp());
        $this->databases->engine()->insert('team_invites', [
            'team_id' => $teamId,
            'email' => $email,
            'token' => $token,
            'invited_by' => $byUserId,
            'expires_at' => $expires,
            'created_at' => $this->clock->iso(),
        ]);

        return $token;
    }

    /**
     * Accept an invite: add the user to the team. Returns the team name.
     */
    public function accept(string $token, int $userId): string
    {
        $db = $this->databases->engine();
        $invite = $db->fetchAssociative('SELECT * FROM team_invites WHERE token = ?', [$token]);
        if ($invite === false) {
            throw new \InvalidArgumentException('This invite link is not valid.');
        }
        if ($invite['accepted_at'] !== null) {
            throw new \InvalidArgumentException('This invite has already been used.');
        }
        if ((string) $invite['expires_at'] < $this->clock->iso()) {
            throw new \InvalidArgumentException('This invite has expired.');
        }

        $now = $this->clock->iso();
        $db->executeStatement(
            'INSERT INTO team_members (team_id, user_id, role, created_at) VALUES (?, ?, \'member\', ?)
             ON CONFLICT (team_id, user_id) DO NOTHING',
            [(int) $invite['team_id'], $userId, $now],
        );
        $db->executeStatement('UPDATE team_invites SET accepted_at = ? WHERE id = ?', [$now, (int) $invite['id']]);

        return (string) $db->fetchOne('SELECT name FROM teams WHERE id = ?', [(int) $invite['team_id']]);
    }

    public function removeMember(int $teamId, int $userId, int $byUserId): void
    {
        if (!$this->isOwner($teamId, $byUserId)) {
            throw new \RuntimeException('Only a team owner can remove members.');
        }
        // Never remove the owner (would orphan the team).
        $this->databases->engine()->executeStatement(
            "DELETE FROM team_members WHERE team_id = ? AND user_id = ? AND role != 'owner'",
            [$teamId, $userId],
        );
    }
}
