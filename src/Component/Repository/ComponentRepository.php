<?php

declare(strict_types=1);

namespace Fastsite\Component\Repository;

use Fastsite\Component\Dto\Component;

interface ComponentRepository
{
    public function findByHandle(string $site, string $handle): ?Component;

    /**
     * @param list<string> $handles
     * @return array<string, Component>
     */
    public function loadMany(string $site, array $handles): array;

    /**
     * @return list<Component>
     */
    public function all(string $site): array;

    /**
     * @param array<string, mixed> $fields
     */
    public function upsert(string $site, string $handle, array $fields): int;

    public function delete(string $site, string $handle): void;

    public function cachedRender(string $site, string $cacheKey): ?string;

    public function storeRender(string $site, string $cacheKey, int $componentId, string $html): void;
}
