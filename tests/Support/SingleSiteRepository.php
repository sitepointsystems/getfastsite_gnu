<?php

declare(strict_types=1);

namespace Fastsite\Tests\Support;

use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;

/**
 * A SiteRepository that knows exactly one site — enough to drive a handler
 * directly in a test without standing up the engine's own sites table.
 *
 * Extracted because the alternative is a sixty-line anonymous class inline in
 * every test that wants to call a handler (MediaTest still carries one; it can
 * move over here whenever that test is next touched).
 */
final class SingleSiteRepository implements SiteRepository
{
    public function __construct(private readonly Site $site)
    {
    }

    public function findByHandle(string $handle): ?Site
    {
        return $handle === $this->site->handle ? $this->site : null;
    }

    public function findAnyByHandle(string $handle): ?Site
    {
        return $this->findByHandle($handle);
    }

    public function findByPrimaryDomain(string $domain): ?Site
    {
        return null;
    }

    /** @return list<Site> */
    public function all(): array
    {
        return [$this->site];
    }

    /** @return list<Site> */
    public function listDeleted(): array
    {
        return [];
    }

    public function softDelete(int $id): void
    {
    }

    public function restore(int $id): void
    {
    }

    public function purge(int $id): void
    {
    }

    /** @param array<string, mixed> $fields */
    public function insert(array $fields): int
    {
        return 0;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
    }
}
