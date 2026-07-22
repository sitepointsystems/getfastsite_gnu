<?php

declare(strict_types=1);

namespace Fastsite\Media\Repository;

use Fastsite\Media\Dto\Media;

interface MediaRepository
{
    public function findById(string $site, int $id): ?Media;

    public function findBySha(string $site, string $sha256): ?Media;

    /**
     * Resolve a files-library reference ({{ filename }}) to a ready file:
     * matches original_name, "slug.ext", or slug. Only kind='file'.
     */
    public function findFileByRef(string $site, string $ref): ?Media;

    /**
     * @param list<string>|null $kinds restrict to these media kinds (image/video/file); null = all
     * @return array{items: list<Media>, total: int}
     */
    public function list(string $site, int $page, int $perPage, ?array $kinds = null): array;

    /**
     * Soft-deleted (trashed) media, newest-deleted first.
     *
     * @param list<string>|null $kinds
     * @return array{items: list<Media>, total: int}
     */
    public function listTrashed(string $site, int $page, int $perPage, ?array $kinds = null): array;

    /** Find by id INCLUDING trashed rows (findById hides them). */
    public function findAnyById(string $site, int $id): ?Media;

    public function softDelete(string $site, int $id): void;

    public function restore(string $site, int $id): void;

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(string $site, array $fields): int;

    /**
     * @param array<string, mixed> $fields
     */
    public function update(string $site, int $id, array $fields): void;

    public function delete(string $site, int $id): void;

    public function replaceVariants(string $site, int $mediaId, iterable $variants): void;

    /**
     * Media referenced by id in a batch, each with variants loaded.
     *
     * @param list<int> $ids
     * @return array<int, Media>
     */
    public function loadManyWithVariants(string $site, array $ids): array;
}
