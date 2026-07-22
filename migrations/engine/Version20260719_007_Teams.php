<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Teams (#2): a group a user creates and invites others into by email. A site
 * can belong to a team (sites.team_id); every team member then reaches that
 * site's dashboard + MCP surface (alongside the existing per-site site_users).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                owner_user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE team_members (
                team_id INTEGER NOT NULL REFERENCES teams (id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
                created_at TEXT NOT NULL,
                PRIMARY KEY (team_id, user_id)
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE team_invites (
                id INTEGER PRIMARY KEY,
                team_id INTEGER NOT NULL REFERENCES teams (id) ON DELETE CASCADE,
                email TEXT NOT NULL,
                token TEXT NOT NULL UNIQUE,
                invited_by INTEGER,
                expires_at TEXT NOT NULL,
                accepted_at TEXT,
                created_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_team_members_user ON team_members (user_id)');
        $db->executeStatement('CREATE INDEX ix_team_invites_email ON team_invites (email)');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN team_id INTEGER');
    }
};
