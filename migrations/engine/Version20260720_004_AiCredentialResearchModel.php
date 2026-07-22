<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-credential model choice for the Research tool — parallels factcheck_model.
 * Defaults to '' so the factory falls back to config `ai.research_model`
 * (perplexity/sonar).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement("ALTER TABLE ai_credentials ADD COLUMN research_model TEXT NOT NULL DEFAULT ''");
    }
};
