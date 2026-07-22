<?php

declare(strict_types=1);

namespace Fastsite\Content\Repository;

use Fastsite\Content\Dto\Content;

interface ContentRepository
{
    public function findBySlug(string $site, string $type, string $slug): ?Content;

    public function findById(string $site, int $id): ?Content;

    public function findBySource(string $site, string $source, string $sourceRef): ?Content;

    /**
     * Published content of one type, newest first.
     *
     * @return list<Content>
     */
    public function listPublished(string $site, string $type): array;

    /**
     * Paginated listing with optional filters, newest first.
     *
     * @return array{items: list<Content>, total: int}
     */
    public function listFiltered(string $site, ?string $type, ?string $status, int $page, int $perPage): array;

    /**
     * Soft-deleted (trashed) content, newest-deleted first.
     *
     * @return list<Content>
     */
    public function listTrashed(string $site, ?string $type): array;

    /**
     * Find by id INCLUDING trashed rows (findById hides them). Used by
     * restore/purge which act on rows the normal reads can't see.
     */
    public function findAnyById(string $site, int $id): ?Content;

    public function softDelete(string $site, int $id): void;

    public function restore(string $site, int $id): void;

    /** Hard-delete a row and its revisions (irreversible). */
    public function purge(string $site, int $id): void;

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(string $site, array $fields): int;

    /**
     * @param array<string, mixed> $fields
     */
    public function update(string $site, int $id, array $fields): void;

    public function insertRevision(string $site, int $contentId, string $snapshotJson, string $createdBy, string $reason): void;

    /**
     * Records a slug change: inserts old→new, flattens chains pointing at
     * the old path, and removes any rule that would shadow the new live path
     * (A→B→A must not leave a redirect loop).
     */
    public function recordSlugChange(string $site, string $oldPath, string $newPath): void;

    public function slugExists(string $site, string $type, string $slug): bool;
}
