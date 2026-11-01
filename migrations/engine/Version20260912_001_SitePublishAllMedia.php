<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Opt a site into publishing its WHOLE media library, not just what pages
 * embed.
 *
 * Media ships referenced-only, which is right for page weight and for the
 * Cloudflare Pages file cap — but it means an image nobody has embedded yet
 * has no public URL at all, and that is exactly the image someone wants a
 * link for (an email, an ad, a social post, another property). Publishing
 * again never fixes it, because the build never copied the file.
 *
 * Default 0: existing sites keep their dist byte-for-byte until the owner
 * asks for this.
 */
return new class implements Migration {
    public function up(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE sites ADD COLUMN publish_all_media INTEGER NOT NULL DEFAULT 0',
        );
    }
};
