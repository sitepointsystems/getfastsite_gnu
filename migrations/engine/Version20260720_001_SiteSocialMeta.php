<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Site-level SEO/social defaults.
 *
 * `meta_description` is the fallback <meta name="description"> for the pages
 * the engine generates itself (home, blog index, 404). Those are built by
 * BuildService::simplePage and have no content row to take a description
 * from, so without this they shipped with no description at all — which
 * Lighthouse reports as an SEO failure on the site's most important page.
 *
 * `social_image_media_id` is the default og:image / twitter:image. The media
 * pipeline already renders a 1200x630 crop (variant purpose 'og') for every
 * uploaded image, so this only has to name which image to use.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement("ALTER TABLE sites ADD COLUMN meta_description TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN social_image_media_id INTEGER');
    }
};
