<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-form overrides for the messages the engine says back to a visitor.
 *
 * The built-in packs (Fastsite\Form\FormMessages) already answer in the site's
 * language, which is the actual bug being fixed — a Danish site was being told
 * "Dato for festen is required". This column is the second layer: a pack can
 * only ever be neutral-polite, and a site's own voice usually is not. It is
 * also the escape hatch for a locale with no built-in pack.
 *
 * Empty object = use the pack as shipped, which is the common case.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            "ALTER TABLE forms ADD COLUMN messages TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(messages))",
        );
    }
};
