<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * How many times a media item's CONTENT has been replaced behind its id.
 *
 * Replacing a file while keeping its id (so every `<img data-media-id>` and
 * `{{ filename }}` embed keeps working) collides head-on with the one thing
 * that makes those assets fast: `_headers` serves /media/* and /files/* as
 * `max-age=31536000, immutable`, and the emitted filenames derive from the
 * item's slug, which derives from its name. Same id + same name = the same
 * URL, and `immutable` tells every browser and the CDN edge not to revalidate
 * it for a year — not even on a hard reload. The replacement would simply not
 * be visible to anyone who had already seen the old one, starting with the
 * person who just uploaded it.
 *
 * So the revision rides in the emitted FILENAME (`{slug}-r{n}`, see
 * Media::publicSlug) while the stored slug, the original_name and the id all
 * stay put: the embeds resolve through the database and never change, and the
 * URL they resolve TO changes on every replace. This is the same reason build
 * assets carry a content hash.
 *
 * Existing media stays at revision 0 and keeps its current filenames, so
 * adding this never re-churns a single already-published site.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE media ADD COLUMN revision INTEGER NOT NULL DEFAULT 0');
    }
};
