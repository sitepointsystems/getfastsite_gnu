<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Error pages become real content: a row carrying `error_status = 404` IS the
 * site's 404 page.
 *
 * Until now the 404 was engine boilerplate nobody could reach. `BuildService`
 * rendered `404.html.twig` with a hard-coded English title ('Not found' plus
 * the SEO suffix) and handed the template no `content` variable at all, so the
 * body was whatever i18n strings the shell happened to ship. On a Danish site
 * the title said "Not found", and there was no way to put a search box, a CTA
 * component or a form on the page — which is most of why anyone customises a
 * 404 in the first place.
 *
 * As a content row it inherits the entire pipeline for free: sanitizing, media
 * and component embeds, forms, revisions, and auto-translation, so each locale
 * gets its own 404 in its own language.
 *
 * ITS OWN COLUMN, not a reserved slug. Same reasoning as `sites.development`
 * not being a `sites.status` value: this decides what the build emits and
 * where, so it is an output invariant rather than a label — and a magic slug
 * would collide the day someone legitimately wants a page at `/404/`.
 *
 * NULL = an ordinary page, so every existing row is untouched and the build
 * keeps its engine-generated fallback for sites that never create one.
 *
 * Deliberately NOT added to `content_translations`: a translation keys off
 * `content_id` and inherits the status from its source row, exactly as it
 * already inherits the `noindex` / `nofollow` flags.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE content ADD COLUMN error_status INTEGER');

        // The build asks "which rows are error pages" once per build, and the
        // partition has to be cheap on a site with hundreds of posts.
        $db->executeStatement('CREATE INDEX ix_content_error ON content (error_status)');
    }
};
