<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * A non-secret masked hint (first…last chars) for an AI credential's key, so
 * the account list can identify a saved key without ever revealing it or
 * decrypting key_enc on every list.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE ai_credentials ADD COLUMN key_hint TEXT');
    }
};
