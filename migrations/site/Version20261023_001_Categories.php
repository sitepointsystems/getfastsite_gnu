<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Post categories: one primary category per post, the way a WordPress
 * permalink has one.
 *
 * ONE category per post, not a many-to-many, because the category is part of
 * the post's URL (`/{category}/{slug}/`) and a URL has exactly one value for
 * each segment. WordPress itself picks one ("primary") for the permalink even
 * when a post sits in several; a second membership would buy an extra archive
 * listing and cost an ambiguity in the one place the engine cannot afford one.
 *
 * `category_id` is NULL for every existing row, so no path moves: a pattern
 * without `{category}` ignores it, and a pattern with it drops the segment.
 * ON DELETE SET NULL is the backstop only — CategoryService refuses to delete
 * a category live posts still use, because that would move their URLs.
 *
 * The name is not translated (no categories_translations table). A
 * multilingual site's archives and crumbs show the primary-language name in
 * every locale; that is the stated limit of this version, not an accident.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description_html TEXT NOT NULL DEFAULT '',
                seo_title TEXT NOT NULL DEFAULT '',
                seo_description TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(
            'ALTER TABLE content ADD COLUMN category_id INTEGER REFERENCES categories (id) ON DELETE SET NULL',
        );
        // The build lists each archive's posts, and a delete asks "is it used".
        $db->executeStatement('CREATE INDEX ix_content_category ON content (category_id)');
    }
};
