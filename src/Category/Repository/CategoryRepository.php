<?php

declare(strict_types=1);

namespace Fastsite\Category\Repository;

use Fastsite\Category\Dto\Category;

interface CategoryRepository
{
    /** @return list<Category> by name */
    public function all(string $site): array;

    public function findBySlug(string $site, string $slug): ?Category;

    public function findById(string $site, int $id): ?Category;

    /** @param array<string, mixed> $fields */
    public function insert(string $site, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function update(string $site, int $id, array $fields): void;

    public function delete(string $site, int $id): void;

    /**
     * Live published POSTS per category id — what decides whether an archive
     * exists and how many pages it has.
     *
     * @return array<int, int>
     */
    public function publishedPostCounts(string $site): array;

    /** Count + newest change, for the preview staleness signature. */
    public function signature(string $site): string;
}
