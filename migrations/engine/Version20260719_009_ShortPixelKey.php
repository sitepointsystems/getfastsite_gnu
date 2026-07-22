<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site optional settings:
 *  - shortpixel_key_enc: the site owner's own ShortPixel API key
 *    (TokenCipher-encrypted). Image optimization is their choice + their
 *    credits, not something the platform operator pays for globally. The global
 *    SHORTPIXEL_API_KEY stays a legacy fallback for single-tenant installs.
 *  - fact_check_enabled: opt-in Perplexity fact-checking for the site's content.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE sites ADD COLUMN shortpixel_key_enc TEXT');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN fact_check_enabled INTEGER NOT NULL DEFAULT 0');
    }
};
