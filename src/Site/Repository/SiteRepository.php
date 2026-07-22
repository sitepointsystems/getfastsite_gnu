<?php

declare(strict_types=1);

namespace Fastsite\Site\Repository;

use Fastsite\Site\Dto\Site;

interface SiteRepository
{
    /** Live sites only (excludes soft-deleted). */
    public function findByHandle(string $handle): ?Site;

    /** Finds a site by handle including soft-deleted ones (trash actions). */
    public function findAnyByHandle(string $handle): ?Site;

    /** Live site whose primary_domain matches (direct-serve host lookup). */
    public function findByPrimaryDomain(string $domain): ?Site;

    /**
     * Live sites only (excludes soft-deleted).
     *
     * @return list<Site>
     */
    public function all(): array;

    /**
     * Soft-deleted sites (the trash view).
     *
     * @return list<Site>
     */
    public function listDeleted(): array;

    public function softDelete(int $id): void;

    public function restore(int $id): void;

    /** Hard-delete the registry row (site_users cascade). Caller removes disk. */
    public function purge(int $id): void;

    /**
     * @param array<string, mixed> $fields column => value
     */
    public function insert(array $fields): int;

    /**
     * @param array<string, mixed> $fields column => value
     */
    public function update(int $id, array $fields): void;
}
