<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * GTM/analytics CTA tracking (default ON): the build annotates every link and
 * button on every page with data-cta (stable cross-language id), data-cta-text
 * (the actual visible text), data-cta-language (the page's locale) and
 * data-cta-location (header/navigation/footer/hero/…), so one GTM container
 * setup tracks CTAs on every site. The attributes are inert markup — zero-JS
 * output is untouched. Existing sites pick it up on their next publish.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN cta_tracking INTEGER NOT NULL DEFAULT 1',
        );
    }
};
