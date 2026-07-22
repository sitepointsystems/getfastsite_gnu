<?php

declare(strict_types=1);

namespace Fastsite\Content\Repository;

use Fastsite\Content\Dto\Content;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

final class DbalContentRepository implements ContentRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function findBySlug(string $site, string $type, string $slug): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT * FROM content WHERE type = ? AND slug = ? AND deleted_at IS NULL',
            [$type, $slug],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function findById(string $site, int $id): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT * FROM content WHERE id = ? AND deleted_at IS NULL',
            [$id],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function findBySource(string $site, string $source, string $sourceRef): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT * FROM content WHERE source = ? AND source_ref = ? AND deleted_at IS NULL',
            [$source, $sourceRef],
        );

        return $row === false ? null : Content::fromRow($row);
    }

    public function listPublished(string $site, string $type): array
    {
        $rows = $this->databases->site($site)->fetchAllAssociative(
            'SELECT * FROM content
             WHERE type = ? AND status = ? AND deleted_at IS NULL
             ORDER BY published_at DESC, id DESC',
            [$type, 'published'],
        );

        return array_map(Content::fromRow(...), $rows);
    }

    public function listFiltered(string $site, ?string $type, ?string $status, int $page, int $perPage): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $db = $this->databases->site($site);
        $total = (int) $db->fetchOne("SELECT COUNT(*) FROM content WHERE {$whereSql}", $params);

        $rows = $db->fetchAllAssociative(
            "SELECT * FROM content WHERE {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        return ['items' => array_map(Content::fromRow(...), $rows), 'total' => $total];
    }

    public function listTrashed(string $site, ?string $type): array
    {
        $where = ['deleted_at IS NOT NULL'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }

        $rows = $this->databases->site($site)->fetchAllAssociative(
            'SELECT * FROM content WHERE ' . implode(' AND ', $where) . ' ORDER BY deleted_at DESC, id DESC',
            $params,
        );

        return array_map(Content::fromRow(...), $rows);
    }

    public function findAnyById(string $site, int $id): ?Content
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT * FROM content WHERE id = ?',
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
                'INSERT INTO redirects (from_path, to_path, status, created_at)
                 VALUES (?, ?, 301, ?)
                 ON CONFLICT (from_path) DO UPDATE SET to_path = excluded.to_path',
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
