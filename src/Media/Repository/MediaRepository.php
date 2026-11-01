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
     * Ready, non-trashed images never (successfully) run through the external
     * optimizer — the work list for the retroactive optimize action/job.
     *
     * @return list<int>
     */
    public function unoptimizedImageIds(string $site): array;

    public function countUnoptimizedImages(string $site): int;

    /**
     * All ready, non-trashed images — the work list for variant regeneration.
     *
     * @return list<int>
     */
    public function readyImageIds(string $site): array;

    /**
     * Every live item of these kinds — what a site publishing its WHOLE
     * library ships on top of what its pages actually embed.
     *
     * @param list<string> $kinds
     * @return list<int>
     */
    public function readyIdsOfKind(string $site, array $kinds): array;

    /**
     * Media referenced by id in a batch, each with variants loaded.
     *
     * @param list<int> $ids
     * @return array<int, Media>
     */
    public function loadManyWithVariants(string $site, array $ids): array;
}
