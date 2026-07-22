<?php

declare(strict_types=1);

namespace Fastsite\Shared\Database;

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Clock\Clock;

/**
 * Slim forward-only migration runner. Doctrine-style versioned files
 * (Version{date}_{seq}_{Description}.php), state in migration_versions
 * inside each database — which is what lets one migration set run against
 * N per-site DBs.
 */
final class Migrator
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Applies pending migrations from $dir. Returns applied version ids.
     *
     * @return list<string>
     */
    public function migrate(Connection $db, string $dir): array
    {
        $db->executeStatement(
            'CREATE TABLE IF NOT EXISTS migration_versions (
                version TEXT PRIMARY KEY,
                executed_at TEXT NOT NULL
            ) STRICT',
        );

        $done = array_column(
            $db->fetchAllAssociative('SELECT version FROM migration_versions'),
            'version',
        );

        $files = glob(rtrim($dir, '/\\') . '/Version*.php') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (\in_array($version, $done, true)) {
                continue;
            }

            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new \RuntimeException($file . ' must return a ' . Migration::class);
            }

            $db->beginTransaction();
            try {
                $migration->up($db);
                $db->executeStatement(
                    'INSERT INTO migration_versions (version, executed_at) VALUES (?, ?)',
                    [$version, $this->clock->iso()],
                );
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw new \RuntimeException(sprintf('Migration %s failed: %s', $version, $e->getMessage()), 0, $e);
            }

            $applied[] = $version;
        }

        return $applied;
    }
}
