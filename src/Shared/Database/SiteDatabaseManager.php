<?php

declare(strict_types=1);

namespace Fastsite\Shared\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Owns every SQLite connection: engine.db plus one site.db per site.
 * Every connection gets the same pragma discipline (WAL, NORMAL sync,
 * busy_timeout, FK enforcement). Nothing else in the codebase opens a DB.
 */
final class SiteDatabaseManager
{
    private const HANDLE_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}$/';

    private ?Connection $engine = null;

    /** @var array<string, Connection> */
    private array $sites = [];

    public function __construct(private readonly string $dataPath)
    {
    }

    public function dataPath(): string
    {
        return $this->dataPath;
    }

    public function engine(): Connection
    {
        if ($this->engine === null) {
            $dir = $this->dataPath . '/engine';
            $this->ensureDir($dir);
            $this->engine = $this->open($dir . '/engine.db');
        }

        return $this->engine;
    }

    /**
     * Connection to an existing site's DB. Site must have been provisioned.
     */
    public function site(string $handle): Connection
    {
        self::assertHandle($handle);

        if (!isset($this->sites[$handle])) {
            $path = $this->sitePath($handle) . '/site.db';
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('Site "%s" has no database — run site:create first.', $handle));
            }
            $this->sites[$handle] = $this->open($path);
        }

        return $this->sites[$handle];
    }

    /**
     * Creates the site's directory tree and opens (creating) its DB.
     * Used only by provisioning.
     */
    public function provisionSite(string $handle): Connection
    {
        self::assertHandle($handle);

        $base = $this->sitePath($handle);
        foreach (['', '/shell', '/media/originals', '/media/variants', '/components', '/build', '/preview', '/repo'] as $sub) {
            $this->ensureDir($base . $sub);
        }

        $this->sites[$handle] = $this->open($base . '/site.db');

        return $this->sites[$handle];
    }

    public function sitePath(string $handle): string
    {
        self::assertHandle($handle);

        return $this->dataPath . '/sites/' . $handle;
    }

    /**
     * Truncate the WAL after builds/backups so the -wal file never grows
     * unboundedly (plan §SQLite discipline).
     */
    public function checkpoint(Connection $db): void
    {
        $db->executeStatement('PRAGMA wal_checkpoint(TRUNCATE)');
    }

    /**
     * Closes one site's connection (if open). Restore swaps the site.db file
     * on disk, which needs the handle released first — on Windows an open
     * handle blocks the rename outright.
     */
    public function closeSite(string $handle): void
    {
        if (isset($this->sites[$handle])) {
            $this->sites[$handle]->close();
            unset($this->sites[$handle]);
        }
    }

    /**
     * Closes every open connection. Needed before deleting site data and on
     * worker shutdown; on Windows an open handle blocks file deletion.
     */
    public function closeAll(): void
    {
        $this->engine?->close();
        $this->engine = null;
        foreach ($this->sites as $connection) {
            $connection->close();
        }
        $this->sites = [];
    }

    public static function assertHandle(string $handle): void
    {
        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid site handle "%s" — lowercase letters, digits and dashes only.',
                $handle,
            ));
        }
    }

    private function open(string $path): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
        ]);

        $connection->executeStatement('PRAGMA journal_mode=WAL');
        $connection->executeStatement('PRAGMA synchronous=NORMAL');
        $connection->executeStatement('PRAGMA busy_timeout=5000');
        $connection->executeStatement('PRAGMA foreign_keys=ON');

        return $connection;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create directory: ' . $dir);
        }
    }
}
