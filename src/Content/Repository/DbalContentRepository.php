<?php

declare(strict_types=1);

namespace Fastsite\Content\Repository;

use Fastsite\Content\Dto\Content;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

final class DbalContentRepository implements ContentRepository
{
    /**
     * Every content read selects the row PLUS its category's slug. The slug is
     * part of a post's URL when the site's post pattern carries `{category}`,
     * and every surface that hands out a URL (build, REST, MCP, the dashboard)
     * reads rows through here — so resolving it once, in the read, is what
     * keeps all of them agreeing with the canonical tag. A scalar subquery
     * rather than a JOIN so no WHERE clause below has to qualify its columns.
     */
    private const COLUMNS = 'content.*, (SELECT categories.slug FROM categories WHERE categories.id = content.category_id) AS category_slug';

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function findBySlug(string $site, string $type, string $slug): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE type = ? AND slug = ? AND deleted_at IS NULL',
            [$type, $slug],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function findByErrorStatus(string $site, int $status): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE error_status = ? AND deleted_at IS NULL',
            [$status],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    /**
     * @return list<Content>
     */
    public function listErrorPages(string $site): array
    {
        // Status order, not id order: the dashboard lists 401 before 404
        // before 410 whatever sequence they were created in.
        return array_map(Content::fromRow(...), $this->databases->site($site)->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE error_status IS NOT NULL AND deleted_at IS NULL ORDER BY error_status',
        ));
    }

    public function findById(string $site, int $id): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE id = ? AND deleted_at IS NULL',
            [$id],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function findBySource(string $site, string $source, string $sourceRef): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE source = ? AND source_ref = ? AND deleted_at IS NULL',
            [$source, $sourceRef],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function listPublished(string $site, string $type): array
    {
        $rows = $this->databases->site($site)->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content
             WHERE type = ? AND status = ? AND deleted_at IS NULL
             ORDER BY published_at DESC, id DESC',
            [$type, 'published'],
        );

        return array_map(Content::fromRow(...), $rows);
    }

    public function countInCategory(string $site, int $categoryId): int
    {
        return (int) $this->databases->site($site)->fetchOne(
            'SELECT COUNT(*) FROM content WHERE category_id = ? AND deleted_at IS NULL',
            [$categoryId],
        );
    }

    public function pathRows(string $site, ?int $categoryId = null): array
    {
        $sql = 'SELECT id, type, slug, status,
                    (SELECT categories.slug FROM categories WHERE categories.id = content.category_id) AS category_slug
                FROM content WHERE deleted_at IS NULL AND error_status IS NULL';
        $params = [];
        if ($categoryId !== null) {
            $sql .= ' AND category_id = ?';
            $params[] = $categoryId;
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'slug' => (string) $r['slug'],
            'status' => (string) $r['status'],
            'category_slug' => $r['category_slug'] !== null ? (string) $r['category_slug'] : null,
        ], $this->databases->site($site)->fetchAllAssociative($sql . ' ORDER BY id', $params));
    }

    public function listFiltered(string $site, ?string $type, ?string $status, int $page, int $perPage, ?string $search = null, ?int $categoryId = null): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($categoryId !== null) {
            $where[] = 'category_id = ?';
            $params[] = $categoryId;
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $this->applySearch($where, $params, $search);
        $whereSql = implode(' AND ', $where);

        $db = $this->databases->site($site);
        $total = (int) $db->fetchOne("SELECT COUNT(*) FROM content WHERE {$whereSql}", $params);

        $rows = $db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM content WHERE {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        return ['items' => array_map(Content::fromRow(...), $rows), 'total' => $total];
    }

    public function listTrashed(string $site, ?string $type, ?string $search = null): array
    {
        $where = ['deleted_at IS NOT NULL'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $this->applySearch($where, $params, $search);

        $rows = $this->databases->site($site)->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE ' . implode(' AND ', $where) . ' ORDER BY deleted_at DESC, id DESC',
            $params,
        );

        return array_map(Content::fromRow(...), $rows);
    }

    /**
     * Free-text needle over the fields an author searches by. LIKE is
     * ASCII-case-insensitive in SQLite; the needle's own %, _ and \ are
     * escaped so a slug like "50%_off" searches for itself rather than
     * matching everything.
     *
     * @param list<string>            $where
     * @param list<string|int|float>  $params
     */
    private function applySearch(array &$where, array &$params, ?string $search): void
    {
        $needle = trim((string) $search);
        if ($needle === '') {
            return;
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
        $columns = ['title', 'slug', 'excerpt', 'seo_title', 'seo_description', 'body_html'];
        $clauses = [];
        foreach ($columns as $column) {
            $clauses[] = "COALESCE({$column}, '') LIKE ? ESCAPE '\\'";
            $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    public function findAnyById(string $site, int $id): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM content WHERE id = ?',
            [$id],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function softDelete(string $site, int $id): void
    {
        $now = $this->clock->iso();
        $this->databases->site($site)->executeStatement(
            'UPDATE content SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $id],
        );
    }

    public function restore(string $site, int $id): void
    {
        // The partial unique indexes on (type,slug) / (source,source_ref)
        // exclude deleted rows, so un-trashing can collide with a live row —
        // that surfaces as a UniqueConstraintViolationException for the caller.
        $this->databases->site($site)->executeStatement(
            'UPDATE content SET deleted_at = NULL, updated_at = ? WHERE id = ?',
            [$this->clock->iso(), $id],
        );
    }

    public function purge(string $site, int $id): void
    {
        $db = $this->databases->site($site);
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM content_revisions WHERE content_id = ?', [$id]);
            $db->executeStatement('DELETE FROM content WHERE id = ?', [$id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function insert(string $site, array $fields): int
    {
        $now = $this->clock->iso();
        $fields += ['created_at' => $now, 'updated_at' => $now];

        $db = $this->databases->site($site);
        $db->insert('content', $fields);

        return (int) $db->lastInsertId();
    }

    public function update(string $site, int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->iso();
        $this->databases->site($site)->update('content', $fields, ['id' => $id]);
    }

    public function insertRevision(string $site, int $contentId, string $snapshotJson, string $createdBy, string $reason): void
    {
        $db = $this->databases->site($site);

        $next = (int) $db->fetchOne(
            'SELECT COALESCE(MAX(rev_no), 0) + 1 FROM content_revisions WHERE content_id = ?',
            [$contentId],
        );

        $db->insert('content_revisions', [
            'content_id' => $contentId,
            'rev_no' => $next,
            'snapshot' => $snapshotJson,
            'created_by' => $createdBy,
            'reason' => $reason,
            'created_at' => $this->clock->iso(),
        ]);

        $db->executeStatement(
            'DELETE FROM content_revisions
             WHERE content_id = ? AND rev_no <= ?',
            [$contentId, $next - 30],
        );
    }

    public function recordSlugChange(string $site, string $oldPath, string $newPath): void
    {
        $db = $this->databases->site($site);

        $db->beginTransaction();
        try {
            $db->executeStatement(
                // A path previously marked Gone (410, no target) now HAS a
                // successor, so it becomes a real redirect again; any other
                // explicit status stays the operator's choice.
                'INSERT INTO redirects (from_path, to_path, status, created_at)
                 VALUES (?, ?, 301, ?)
                 ON CONFLICT (from_path) DO UPDATE SET
                     to_path = excluded.to_path,
                     status = CASE WHEN redirects.status = 410 THEN 301 ELSE redirects.status END',
                [$oldPath, $newPath, $this->clock->iso()],
            );

            // Flatten chains: anything that pointed at the old path now
            // points at the new one (no multi-hop redirects).
            $db->executeStatement(
                'UPDATE redirects SET to_path = ? WHERE to_path = ?',
                [$newPath, $oldPath],
            );

            // The new path is live again — a rule FROM it would shadow the
            // page (and A→B→A would loop). Self-loops from the flattening
            // above die here too.
            $db->executeStatement('DELETE FROM redirects WHERE from_path = ?', [$newPath]);
            $db->executeStatement('DELETE FROM redirects WHERE from_path = to_path');

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function slugExists(string $site, string $type, string $slug): bool
    {
        return (bool) $this->databases->site($site)->fetchOne(
            'SELECT 1 FROM content WHERE type = ? AND slug = ? AND deleted_at IS NULL',
            [$type, $slug],
        );
    }
}
