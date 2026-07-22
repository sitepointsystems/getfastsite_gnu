<?php

declare(strict_types=1);

namespace Fastsite\Shared\Database;

use Doctrine\DBAL\Connection;

/**
 * One schema migration. Files under migrations/ return an anonymous class
 * implementing this; the version id is the file name (Version{date}_{seq}_{Description}).
 */
interface Migration
{
    public function up(Connection $db): void;
}
