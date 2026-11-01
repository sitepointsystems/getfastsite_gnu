<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Workspaces: a grouping layer for keeping customers apart. A site belongs to
 * at most ONE workspace (sites.workspace_id); the dashboard filters every
 * site list to the selected workspace (POST-selected, remembered per browser).
 * Access mirrors the site model: admin → all; otherwise the workspace's
 * owner (its creator) or a member of the team it is attached to. No FK
 * cascades on purpose: deleting the owning user must NOT dissolve a customer
 * grouping (unlike teams), and workspace deletion detaches sites explicitly
 * in WorkspaceService so the sites themselves are never touched.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE workspaces (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                owner_user_id INTEGER NOT NULL,
                team_id INTEGER,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('ALTER TABLE sites ADD COLUMN workspace_id INTEGER');
        $db->executeStatement('CREATE INDEX ix_sites_workspace ON sites (workspace_id)');
    }
};
