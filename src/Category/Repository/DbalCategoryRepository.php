<?php

declare(strict_types=1);

namespace Fastsite\Category\Repository;

use Fastsite\Category\Dto\Category;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

final class DbalCategoryRepository implements CategoryRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function all(string $site): array
    {
        return array_map(Category::fromRow(...), $this->databases->site($site)->fetchAllAssociative(
            'SELECT * FROM categories ORDER BY name COLLATE NOCASE, id',
        ));
    }

    public function findBySlug(string $site, string $slug): ?Category
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM categories WHERE slug = ?', [$slug]);

        return $row === false ? null : Category::fromRow($row);
    }

    public function findById(string $site, int $id): ?Category
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM categories WHERE id = ?', [$id]);

        return $row === false ? null : Category::fromRow($row);
    }

    public function insert(string $site, array $fields): int
    {
        $now = $this->clock->iso();
        $db = $this->databases->site($site);
        $db->insert('categories', $fields + ['created_at' => $now, 'updated_at' => $now]);

        return (int) $db->lastInsertId();
    }

    public function update(string $site, int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->iso();
        $this->databases->site($site)->update('categories', $fields, ['id' => $id]);
    }

    public function delete(string $site, int $id): void
    {
        $this->databases->site($site)->executeStatement('DELETE FROM categories WHERE id = ?', [$id]);
    }

    public function publishedPostCounts(string $site): array
    {
        $rows = $this->databases->site($site)->fetchAllAssociative(
            "SELECT category_id, COUNT(*) n FROM content
             WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL AND category_id IS NOT NULL
             GROUP BY category_id",
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['category_id']] = (int) $row['n'];
        }

        return $out;
    }

    public function signature(string $site): string
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT COUNT(*) n, COALESCE(MAX(updated_at), \'\') m FROM categories',
        );

        return $row === false ? '' : $row['n'] . '|' . $row['m'];
    }
}
