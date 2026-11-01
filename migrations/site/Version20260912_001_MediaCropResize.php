<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Non-destructive crop + resize for images.
 *
 * The uploaded original is never touched: the crop rect and the target width
 * are STORED and applied by the sharp pipeline every time variants are built.
 * That is what makes a bad drag free — re-crop or clear it forever — and it is
 * why the crop has to live in the database rather than in the pixels.
 *
 * The rect is stored as FRACTIONS of the original (0..1), not pixels, and that
 * is deliberate: fractions stay meaningful no matter what the source is, so
 * nothing here has to know the original's dimensions (which PHP cannot read
 * reliably for avif anyway — sharp does it, on its own post-rotation
 * geometry). resize_width is absolute pixels, because "make it 1200 wide" is
 * the thing people actually mean.
 *
 * NULL everywhere = untransformed, which is every existing row.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        foreach (['crop_x', 'crop_y', 'crop_w', 'crop_h'] as $column) {
            $db->executeStatement('ALTER TABLE media ADD COLUMN ' . $column . ' REAL');
        }
        $db->executeStatement('ALTER TABLE media ADD COLUMN resize_width INTEGER');
    }
};
