<?php

declare(strict_types=1);

namespace Fastsite\Component\Repository;

use Fastsite\Component\Dto\Component;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

final class DbalComponentRepository implements ComponentRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function findByHandle(string $site, string $handle): ?Component
    {
        $row = $this->databases->site($site)->fetchAssociative('SELECT * FROM components WHERE handle = ?', [$handle]);

        return $row === false ? null : Component::fromRow($row);
    }

    public function loadMany(string $site, array $handles): array
    {
        if ($handles === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($handles), '?'));
        $rows = $this->databases->site($site)->fetchAllAssociative(
            "SELECT * FROM components WHERE handle IN ({$placeholders})",
            $handles,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['handle']] = Component::fromRow($row);
        }

        return $out;
    }

    public function all(string $site): array
    {
        $rows = $this->databases->site($site)->fetchAllAssociative('SELECT * FROM components ORDER BY handle');

        return array_map(Component::fromRow(...), $rows);
    }

    public function upsert(string $site, string $handle, array $fields): int
    {
        $db = $this->databases->site($site);
        $now = $this->clock->iso();

        $existing = $db->fetchOne('SELECT id FROM components WHERE handle = ?', [$handle]);
        if ($existing !== false) {
            $fields['updated_at'] = $now;
            $db->update('components', $fields, ['id' => (int) $existing]);

            return (int) $existing;
        }

        $db->insert('components', $fields + ['handle' => $handle, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $db->lastInsertId();
    }

    public function delete(string $site, string $handle): void
    {
        $db = $this->databases->site($site);
        $id = $db->fetchOne('SELECT id FROM components WHERE handle = ?', [$handle]);
        if ($id === false) {
            return;
        }
        // Clear cached renders first (they reference the component id).
        $db->executeStatement('DELETE FROM component_renders WHERE component_id = ?', [(int) $id]);
        $db->executeStatement('DELETE FROM components WHERE id = ?', [(int) $id]);
    }

    public function cachedRender(string $site, string $cacheKey): ?string
    {
        $html = $this->databases->site($site)->fetchOne('SELECT html FROM component_renders WHERE cache_key = ?', [$cacheKey]);

        return $html === false ? null : (string) $html;
    }

    public function storeRender(string $site, string $cacheKey, int $componentId, string $html): void
    {
        $this->databases->site($site)->executeStatement(
            'INSERT INTO component_renders (cache_key, component_id, html, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (cache_key) DO UPDATE SET html = excluded.html',
            [$cacheKey, $componentId, $html, $this->clock->iso()],
        );
    }
}
