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
     * The live row claiming this HTTP status as its error page, if any.
     * Ignores trashed rows, so a deleted 404 never blocks its replacement.
     */
    public function findByErrorStatus(string $site, int $status): ?Content;

    /**
     * Every live error-page row, lowest status first.
     *
     * @return list<Content>
     */
    public function listErrorPages(string $site): array;

    /**
     * Published content of one type, newest first.
     *
     * @return list<Content>
     */
    public function listPublished(string $site, string $type): array;

    /**
     * Paginated listing with optional filters, newest first. $search is a free
     * text needle matched (case-insensitively) across the fields an author
     * would search by — title, slug, excerpt, the SEO overrides and the body.
     * $categoryId narrows to one category's posts.
     *
     * @return array{items: list<Content>, total: int}
     */
    public function listFiltered(string $site, ?string $type, ?string $status, int $page, int $perPage, ?string $search = null, ?int $categoryId = null): array;

    /** How many live (not trashed) rows sit in a category. */
    public function countInCategory(string $site, int $categoryId): int;

    /**
     * Every LIVE row's id, type, slug, status and category slug — the minimum
     * needed to work out what a permalink change moves, without loading bodies.
     *
     * @return list<array{id: int, type: string, slug: string, status: string, category_slug: ?string}>
     */
    public function pathRows(string $site, ?int $categoryId = null): array;

    /**
     * Soft-deleted (trashed) content, newest-deleted first. $search behaves as
     * in {@see listFiltered}.
     *
     * @return list<Content>
     */
    public function listTrashed(string $site, ?string $type, ?string $search = null): array;

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
