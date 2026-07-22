<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Preferred canonical host: apex (default) or www. The apex <link rel=canonical>
 * stays the in-engine dedup signal; the actual host-level 301 (www↔apex) is a
 * Cloudflare zone Redirect Rule (Pages _redirects can't match host) — this
 * stores the operator's choice and drives the settings UI + guidance.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement("ALTER TABLE sites ADD COLUMN canonical_host TEXT NOT NULL DEFAULT 'apex' CHECK (canonical_host IN ('apex', 'www'))");
    }
};
