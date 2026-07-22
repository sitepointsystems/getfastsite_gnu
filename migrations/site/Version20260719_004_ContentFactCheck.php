<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Stores the latest Perplexity fact-check report per content row (JSON:
 * summary + per-claim verdicts + sources + checked_at). On-demand only —
 * populated when the author runs a fact-check from the editor / API / MCP.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE content ADD COLUMN fact_check TEXT');
    }
};
