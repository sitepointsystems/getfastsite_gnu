<?php

declare(strict_types=1);

namespace Fastsite\Site\Repository;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;

final class DbalSiteRepository implements SiteRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function findByHandle(string $handle): ?Site
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM sites WHERE handle = ? AND deleted_at IS NULL',
            [$handle],
        );

        return $row === false ? null : Site::fromRow($row);
    }

    public function findAnyByHandle(string $handle): ?Site
    {
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM sites WHERE handle = ?',
            [$handle],
        );

        return $row === false ? null : Site::fromRow($row);
    }

    public function findByPrimaryDomain(string $domain): ?Site
    {
        if ($domain === '') {
            return null;
        }
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT * FROM sites WHERE primary_domain = ? AND deleted_at IS NULL',
            [$domain],
        );

        return $row === false ? null : Site::fromRow($row);
    }

    public function all(): array
    {
        $rows = $this->databases->engine()->fetchAllAssociative(
            'SELECT * FROM sites WHERE deleted_at IS NULL ORDER BY handle',
        );

        return array_map(Site::fromRow(...), $rows);
    }

    public function listDeleted(): array
    {
        $rows = $this->databases->engine()->fetchAllAssociative(
            'SELECT * FROM sites WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );

        return array_map(Site::fromRow(...), $rows);
    }

    public function softDelete(int $id): void
    {
        $now = $this->clock->iso();
        $this->databases->engine()->executeStatement(
            'UPDATE sites SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $id],
        );
    }

    public function restore(int $id): void
    {
        $this->databases->engine()->executeStatement(
            'UPDATE sites SET deleted_at = NULL, updated_at = ? WHERE id = ?',
            [$this->clock->iso(), $id],
        );
    }

    public function purge(int $id): void
    {
        $this->databases->engine()->executeStatement('DELETE FROM sites WHERE id = ?', [$id]);
    }

    public function insert(array $fields): int
    {
        $now = $this->clock->iso();
        $fields += ['created_at' => $now, 'updated_at' => $now];

        $db = $this->databases->engine();
        $db->insert('sites', $fields);

        return (int) $db->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->iso();
        $this->databases->engine()->update('sites', $fields, ['id' => $id]);
    }
}
