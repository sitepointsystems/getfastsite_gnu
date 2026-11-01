<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site image compression level — ONE knob driving both the ShortPixel
 * mode (lossy/glossy/lossless) and the sharp variant quality presets, so a
 * site can choose smallest-files vs pixel-faithful without touching env.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN image_compression TEXT NOT NULL DEFAULT 'lossy' CHECK (image_compression IN ('lossy', 'glossy', 'lossless'))",
        );
    }
};
