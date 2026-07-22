<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Webhooks become user-controlled (#3): an owner column so a site member (not
 * just an admin) can create + manage the webhooks for their own site.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE webhook_integrations ADD COLUMN user_id INTEGER');
    }
};
