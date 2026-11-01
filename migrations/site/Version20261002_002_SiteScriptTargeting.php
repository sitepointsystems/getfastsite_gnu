<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Scripts choose their pages.
 *
 * `site_scripts` had nine columns and not one of them was a target, so one
 * enabled tag applied to the whole site: it injected into every `.html`,
 * relaxed the CSP everywhere, and stripped the zero-JS and external-resource
 * FAILs from every page. A GTM container on one landing page therefore spent
 * the 100/100/100/100 guarantee on all eighty blog posts — the thing the
 * product is sold on.
 *
 * The targeting model is `component_hooks`' verbatim — `target` plus
 * include/exclude PRIMARY slugs, exclude wins — so the product has ONE
 * targeting vocabulary rather than two that drift apart. `ComponentHook`'s
 * matcher needed no change to cover the pages that have no content row
 * either: it tests `target` by negative arms only, so an unknown pseudo-type
 * (`index` for the blog index, `error` for a 404) is rejected by `posts` and
 * `pages` and accepted by `all`.
 *
 * DEFAULT 'all' WITH EMPTY LISTS IS EXACTLY TODAY'S BEHAVIOUR, which is the
 * point: every script already live keeps applying everywhere, and nobody's
 * analytics quietly stops recording the morning after this deploys.
 *
 * SQLite accepts CHECK on ALTER TABLE ADD COLUMN and enforces it (verified,
 * not assumed), so these carry the same constraints a fresh CREATE TABLE
 * would give them instead of relying on the service alone.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            "ALTER TABLE site_scripts ADD COLUMN target TEXT NOT NULL DEFAULT 'all'
                 CHECK (target IN ('all', 'posts', 'pages'))",
        );
        $db->executeStatement(
            "ALTER TABLE site_scripts ADD COLUMN include_slugs TEXT NOT NULL DEFAULT '[]'
                 CHECK (json_valid(include_slugs))",
        );
        $db->executeStatement(
            "ALTER TABLE site_scripts ADD COLUMN exclude_slugs TEXT NOT NULL DEFAULT '[]'
                 CHECK (json_valid(exclude_slugs))",
        );
    }
};
