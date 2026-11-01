<?php

declare(strict_types=1);

namespace Fastsite\Media\Repository;

use Fastsite\Media\Dto\Media;
use Fastsite\Media\Dto\MediaVariant;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

final class DbalMediaRepository implements MediaRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function findById(string $site, int $id): ?Media
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM media WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === false) {
            return null;
        }

        return Media::fromRow($row, $this->variants($site, [$id])[$id] ?? []);
    }

    public function findAnyById(string $site, int $id): ?Media
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM media WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }

        return Media::fromRow($row, $this->variants($site, [$id])[$id] ?? []);
    }

    public function findBySha(string $site, string $sha256): ?Media
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM media WHERE sha256 = ?', [$sha256]);

        return $row === false ? null : Media::fromRow($row);
    }

    public function findFileByRef(string $site, string $ref): ?Media
    {
        $row = $this->databases->site($site)->fetchAssociative(
            "SELECT * FROM media
             WHERE kind = 'file' AND status = 'ready' AND deleted_at IS NULL
               AND (original_name = ? OR (slug || '.' || ext) = ? OR slug = ?)
             ORDER BY id DESC LIMIT 1",
            [$ref, $ref, $ref],
        );

        return $row === false ? null : Media::fromRow($row);
    }

    public function list(string $site, int $page, int $perPage, ?array $kinds = null): array
    {
        return $this->listWhere($site, 'deleted_at IS NULL', $page, $perPage, $kinds);
    }

    public function listTrashed(string $site, int $page, int $perPage, ?array $kinds = null): array
    {
        return $this->listWhere($site, 'deleted_at IS NOT NULL', $page, $perPage, $kinds, 'deleted_at DESC, id DESC');
    }

    /**
     * @param list<string>|null $kinds
     * @return array{items: list<Media>, total: int}
     */
    private function listWhere(string $site, string $baseWhere, int $page, int $perPage, ?array $kinds, string $order = 'id DESC'): array
    {
        $db = $this->databases->site($site);

        $where = [$baseWhere];
        $params = [];
        if ($kinds !== null && $kinds !== []) {
            $where[] = 'kind IN (' . implode(',', array_fill(0, \count($kinds), '?')) . ')';
            $params = array_values($kinds);
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM media' . $whereSql, $params);
        $rows = $db->fetchAllAssociative(
            'SELECT * FROM media' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $variants = $this->variants($site, $ids);

        return [
            'items' => array_map(fn (array $r): Media => Media::fromRow($r, $variants[(int) $r['id']] ?? []), $rows),
            'total' => $total,
        ];
    }

    public function softDelete(string $site, int $id): void
    {
        $now = $this->clock->iso();
        $this->databases->site($site)->executeStatement(
            'UPDATE media SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $id],
        );
    }

    public function restore(string $site, int $id): void
    {
        $this->databases->site($site)->executeStatement(
            'UPDATE media SET deleted_at = NULL, updated_at = ? WHERE id = ?',
            [$this->clock->iso(), $id],
        );
    }

    public function insert(string $site, array $fields): int
    {
        $now = $this->clock->iso();
        $fields += ['created_at' => $now, 'updated_at' => $now];

        $db = $this->databases->site($site);
        $db->insert('media', $fields);

        return (int) $db->lastInsertId();
    }

    public function update(string $site, int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->iso();
        $this->databases->site($site)->update('media', $fields, ['id' => $id]);
    }

    public function delete(string $site, int $id): void
    {
        // variants cascade via FK ON DELETE CASCADE
        $this->databases->site($site)->delete('media', ['id' => $id]);
    }

    public function replaceVariants(string $site, int $mediaId, iterable $variants): void
    {
        $db = $this->databases->site($site);
        $db->executeStatement('DELETE FROM media_variants WHERE media_id = ?', [$mediaId]);
        foreach ($variants as $v) {
            $db->insert('media_variants', [
                'media_id' => $mediaId,
                'purpose' => $v['purpose'],
                'format' => $v['format'],
                'width' => $v['width'],
                'height' => $v['height'],
                'disk_path' => $v['disk_path'],
                'bytes' => $v['bytes'],
            ]);
        }
    }

    private const UNOPTIMIZED_WHERE = "kind = 'image' AND status = 'ready' AND deleted_at IS NULL AND optimized_at IS NULL";

    public function unoptimizedImageIds(string $site): array
    {
        $rows = $this->databases->site($site)->fetchFirstColumn(
            'SELECT id FROM media WHERE ' . self::UNOPTIMIZED_WHERE . ' ORDER BY id',
        );

        return array_map(intval(...), $rows);
    }

    public function countUnoptimizedImages(string $site): int
    {
        return (int) $this->databases->site($site)->fetchOne(
            'SELECT COUNT(*) FROM media WHERE ' . self::UNOPTIMIZED_WHERE,
        );
    }

    public function readyImageIds(string $site): array
    {
        $rows = $this->databases->site($site)->fetchFirstColumn(
            "SELECT id FROM media WHERE kind = 'image' AND status = 'ready' AND deleted_at IS NULL ORDER BY id",
        );

        return array_map(intval(...), $rows);
    }

    public function readyIdsOfKind(string $site, array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }
        $rows = $this->databases->site($site)->fetchFirstColumn(
            'SELECT id FROM media WHERE kind IN (' . implode(',', array_fill(0, \count($kinds), '?')) . ")
               AND status = 'ready' AND deleted_at IS NULL ORDER BY id",
            array_values($kinds),
        );

        return array_map(intval(...), $rows);
    }

    public function loadManyWithVariants(string $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $rows = $this->databases->site($site)->fetchAllAssociative(
            "SELECT * FROM media WHERE id IN ({$placeholders})",
            $ids,
        );
        $variants = $this->variants($site, $ids);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $out[$id] = Media::fromRow($row, $variants[$id] ?? []);
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<MediaVariant>>
     */
    private function variants(string $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $rows = $this->databases->site($site)->fetchAllAssociative(
            "SELECT * FROM media_variants WHERE media_id IN ({$placeholders}) ORDER BY width",
            $ids,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['media_id']][] = MediaVariant::fromRow($row);
        }

        return $out;
    }
}
