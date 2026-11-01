<?php

declare(strict_types=1);

namespace Fastsite\Component\Service;

use Fastsite\Component\Dto\ComponentHook;
use Fastsite\Component\Repository\ComponentRepository;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Component hooks: "insert this component into these pages at this position"
 * rules applied at build (HookInjector) so a CTA reaches every targeted page
 * without a single content edit. Multiple hooks may land on the same page or
 * type; exclude lists keep it off specific pages (home, terms, …).
 */
final class ComponentHookService
{
    private const MAX_PARAGRAPH = 500;
    private const MAX_SLUGS = 200;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly ComponentRepository $components,
    ) {
    }

    /**
     * @return list<ComponentHook>
     */
    public function list(string $handle, bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM component_hooks';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY priority, id';

        return array_map(ComponentHook::fromRow(...), $this->databases->site($handle)->fetchAllAssociative($sql));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(string $handle, array $input): ComponentHook
    {
        $componentHandle = strtolower(trim((string) ($input['component_handle'] ?? $input['component'] ?? '')));
        if ($componentHandle === '') {
            throw new \InvalidArgumentException('component_handle is required.');
        }
        // Existence surfaces at save, not as a silent empty render at build.
        if ($this->components->findByHandle($handle, $componentHandle) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown component "%s" — create it first (upsert_component).', $componentHandle));
        }

        $position = (string) ($input['position'] ?? 'bottom');
        if (!\in_array($position, ComponentHook::POSITIONS, true)) {
            throw new \InvalidArgumentException('position must be one of: ' . implode(', ', ComponentHook::POSITIONS));
        }
        $paragraph = (int) ($input['paragraph'] ?? 1);
        if ($paragraph < 1 || $paragraph > self::MAX_PARAGRAPH) {
            throw new \InvalidArgumentException('paragraph must be between 1 and ' . self::MAX_PARAGRAPH . '.');
        }
        $target = (string) ($input['target'] ?? 'posts');
        if (!\in_array($target, ComponentHook::TARGETS, true)) {
            throw new \InvalidArgumentException('target must be one of: ' . implode(', ', ComponentHook::TARGETS));
        }

        $values = $input['values'] ?? [];
        if (\is_string($values)) {
            $values = trim($values) === '' ? [] : json_decode($values, true);
            if (!\is_array($values)) {
                throw new \InvalidArgumentException('values must be a JSON object.');
            }
        }
        if (!\is_array($values)) {
            throw new \InvalidArgumentException('values must be a JSON object.');
        }

        // Same enabled coercion as site scripts: read once, form '0'/'false' off.
        $enabled = $input['enabled'] ?? true;

        $fields = [
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 200),
            'component_handle' => $componentHandle,
            'values_json' => json_encode($values === [] ? new \stdClass() : $values, JSON_THROW_ON_ERROR),
            'position' => $position,
            'paragraph' => $paragraph,
            'target' => $target,
            'include_slugs' => json_encode(self::slugList($input['include_slugs'] ?? []), JSON_THROW_ON_ERROR),
            'exclude_slugs' => json_encode(self::slugList($input['exclude_slugs'] ?? []), JSON_THROW_ON_ERROR),
            'priority' => (int) ($input['priority'] ?? 100),
            'enabled' => ($enabled && $enabled !== '0' && $enabled !== 'false') ? 1 : 0,
            'updated_at' => $this->clock->iso(),
        ];

        $db = $this->databases->site($handle);
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0 && $db->fetchOne('SELECT 1 FROM component_hooks WHERE id = ?', [$id]) !== false) {
            $db->update('component_hooks', $fields, ['id' => $id]);
        } else {
            $fields['created_at'] = $this->clock->iso();
            $db->insert('component_hooks', $fields);
            $id = (int) $db->lastInsertId();
        }

        $row = $db->fetchAssociative('SELECT * FROM component_hooks WHERE id = ?', [$id]);
        \assert($row !== false);

        return ComponentHook::fromRow($row);
    }

    public function delete(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('DELETE FROM component_hooks WHERE id = ?', [$id]);
    }

    /**
     * True when any ENABLED hook injects this component — the signal that a
     * component edit changes live pages and must republish (a hook page has
     * no content row to go stale, so nothing else would trigger the rebuild).
     */
    public function referencing(string $handle, string $componentHandle): bool
    {
        try {
            return (bool) $this->databases->site($handle)->fetchOne(
                'SELECT 1 FROM component_hooks WHERE enabled = 1 AND component_handle = ? LIMIT 1',
                [strtolower(trim($componentHandle))],
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Preview-staleness fingerprint: every enabled hook row PLUS the bundle
     * hash of the component it injects — editing either changes built pages
     * with no content-row timestamp moving, the exact symptom the preview
     * signature exists to catch. '' when the table is missing (pre-migration).
     */
    public function signature(string $handle): string
    {
        try {
            $rows = $this->databases->site($handle)->fetchAllAssociative(
                'SELECT h.id, h.component_handle, h.values_json, h.position, h.paragraph, h.target,
                        h.include_slugs, h.exclude_slugs, h.priority, h.label, COALESCE(c.bundle_hash, \'\') AS bundle_hash
                 FROM component_hooks h LEFT JOIN components c ON c.handle = h.component_handle
                 WHERE h.enabled = 1 ORDER BY h.id',
            );

            return $rows === [] ? '' : md5(json_encode($rows, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Normalize a slug list from any transport shape: an array, or a comma/
     * newline-separated string (the dashboard form). Slashes are trimmed so
     * "/terms/" and "terms" match the same page.
     *
     * @return list<string>
     */
    private static function slugList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = preg_split('/[,\n]/', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('Slug lists must be an array or a comma-separated string.');
        }

        $out = [];
        foreach ($raw as $slug) {
            if (!\is_string($slug) && !\is_numeric($slug)) {
                continue;
            }
            $slug = strtolower(trim(trim((string) $slug), '/'));
            if ($slug !== '' && !\in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        if (\count($out) > self::MAX_SLUGS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_SLUGS . ' slugs per list.');
        }

        return $out;
    }
}
