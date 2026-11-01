<?php

declare(strict_types=1);

namespace Fastsite\Workspace\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Workspaces: the customer-separation layer. A site belongs to at most one
 * workspace (sites.workspace_id); the dashboard filters its site lists to the
 * selected workspace. THE workspace access model (enforced identically on the
 * dashboard, REST and MCP — a caller without it gets a 403): admin → every
 * workspace; everyone else only as the workspace's OWNER (its creator) or as
 * a member of the team the workspace is attached to. Selecting, listing and
 * moving sites into a workspace all go through canAccess; rename/delete
 * additionally require canManage (admin or owner).
 */
final class WorkspaceService
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Workspaces this user may see and select: all for admins, otherwise the
     * ones they own or reach through the attached team.
     *
     * @return list<array{id: int, name: string, owner_user_id: int, team_id: int|null, site_count: int}>
     */
    public function listForUser(int $userId): array
    {
        $db = $this->databases->engine();
        $sql = 'SELECT w.id, w.name, w.owner_user_id, w.team_id,
                       (SELECT COUNT(*) FROM sites s WHERE s.workspace_id = w.id AND s.deleted_at IS NULL) AS site_count
                FROM workspaces w';
        $rows = $this->isAdmin($userId)
            ? $db->fetchAllAssociative($sql . ' ORDER BY w.name')
            : $db->fetchAllAssociative(
                $sql . ' WHERE w.owner_user_id = ?
                         OR (w.team_id IS NOT NULL AND w.team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))
                       ORDER BY w.name',
                [$userId, $userId],
            );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'owner_user_id' => (int) $r['owner_user_id'],
            'team_id' => $r['team_id'] !== null ? (int) $r['team_id'] : null,
            'site_count' => (int) $r['site_count'],
        ], $rows);
    }

    /**
     * @return array{id: int, name: string, owner_user_id: int, team_id: int|null}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT id, name, owner_user_id, team_id FROM workspaces WHERE id = ?',
            [$id],
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
        ];
    }

    /**
     * May this user see / select / move sites into the workspace? Admin, its
     * owner, or a member of its attached team. A nonexistent workspace is
     * never accessible, so every transport's guard also covers bogus ids.
     */
    public function canAccess(int $userId, int $workspaceId): bool
    {
        $workspace = $this->find($workspaceId);
        if ($workspace === null) {
            return false;
        }
        if ($this->isAdmin($userId) || $workspace['owner_user_id'] === $userId) {
            return true;
        }

        return $workspace['team_id'] !== null && (bool) $this->databases->engine()->fetchOne(
            'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?',
            [$workspace['team_id'], $userId],
        );
    }

    /**
     * May this user rename/delete the workspace or change its team? Admin or
     * the owner — team members select and use it, but never reshape it
     * (mirrors Dash::canManageSite).
     */
    public function canManage(int $userId, int $workspaceId): bool
    {
        $workspace = $this->find($workspaceId);
        if ($workspace === null) {
            return false;
        }

        return $this->isAdmin($userId) || $workspace['owner_user_id'] === $userId;
    }

    public function create(int $ownerUserId, string $name, ?int $teamId = null): int
    {
        $db = $this->databases->engine();
        $now = $this->clock->iso();
        $db->insert('workspaces', [
            'name' => $this->cleanName($name),
            'owner_user_id' => $ownerUserId,
            'team_id' => $teamId !== null && $teamId > 0 ? $teamId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    public function update(int $id, string $name, ?int $teamId): void
    {
        if ($this->find($id) === null) {
            throw new \RuntimeException('Unknown workspace: ' . $id);
        }
        $this->databases->engine()->update('workspaces', [
            'name' => $this->cleanName($name),
            'team_id' => $teamId !== null && $teamId > 0 ? $teamId : null,
            'updated_at' => $this->clock->iso(),
        ], ['id' => $id]);
    }

    /**
     * Deletes the workspace. Its sites are detached (workspace_id NULL) —
     * never touched beyond the grouping.
     */
    public function delete(int $id): void
    {
        $db = $this->databases->engine();
        $db->executeStatement('UPDATE sites SET workspace_id = NULL WHERE workspace_id = ?', [$id]);
        $db->executeStatement('DELETE FROM workspaces WHERE id = ?', [$id]);
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
        if ($name === '') {
            throw new \InvalidArgumentException('A workspace name is required.');
        }

        return mb_substr($name, 0, 200);
    }

    private function isAdmin(int $userId): bool
    {
        return $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$userId]) === 'admin';
    }
}
