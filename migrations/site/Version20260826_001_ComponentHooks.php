<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Component hooks: site-level rules that inject a component (typically a CTA)
 * into targeted pages at build time — after the Nth paragraph, mid-body, top
 * or bottom — WITHOUT touching the stored content. target scopes by content
 * type; include/exclude narrow by primary slug (stable across locales), so
 * "all posts except terms" needs no per-page edits.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE component_hooks (
                id INTEGER PRIMARY KEY,
                label TEXT NOT NULL DEFAULT '',
                component_handle TEXT NOT NULL,
                values_json TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(values_json)),
                position TEXT NOT NULL DEFAULT 'bottom'
                    CHECK (position IN ('top', 'after_paragraph', 'middle', 'bottom')),
                paragraph INTEGER NOT NULL DEFAULT 1,
                target TEXT NOT NULL DEFAULT 'posts' CHECK (target IN ('all', 'posts', 'pages')),
                include_slugs TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(include_slugs)),
                exclude_slugs TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(exclude_slugs)),
                priority INTEGER NOT NULL DEFAULT 100,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_component_hooks_order ON component_hooks (priority, id)');
    }
};
