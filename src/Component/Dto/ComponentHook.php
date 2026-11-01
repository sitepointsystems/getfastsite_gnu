<?php

declare(strict_types=1);

namespace Fastsite\Component\Dto;

use Fastsite\Content\Service\Slugger;

/**
 * A build-time injection rule: place one component (usually a CTA) into every
 * targeted page at a position — top, after paragraph N, middle, or bottom —
 * without editing the stored content. Targeting is by content type plus
 * optional include/exclude PRIMARY slugs, so one rule covers every locale's
 * variant of a page identically.
 */
final readonly class ComponentHook
{
    public const POSITIONS = ['top', 'after_paragraph', 'middle', 'bottom'];
    public const TARGETS = ['all', 'posts', 'pages'];

    /**
     * @param array<string, mixed> $values component data-values
     * @param list<string> $includeSlugs empty = every page the target matches
     * @param list<string> $excludeSlugs always wins over include/target
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $componentHandle,
        public array $values,
        public string $position,
        public int $paragraph,
        public string $target,
        public array $includeSlugs,
        public array $excludeSlugs,
        public int $priority,
        public bool $enabled,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var array<string, mixed> $values */
        $values = json_decode((string) $row['values_json'], true) ?: [];
        /** @var list<string> $include */
        $include = json_decode((string) $row['include_slugs'], true) ?: [];
        /** @var list<string> $exclude */
        $exclude = json_decode((string) $row['exclude_slugs'], true) ?: [];

        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            componentHandle: (string) $row['component_handle'],
            values: $values,
            position: (string) $row['position'],
            paragraph: (int) $row['paragraph'],
            target: (string) $row['target'],
            includeSlugs: $include,
            excludeSlugs: $exclude,
            priority: (int) $row['priority'],
            enabled: (int) $row['enabled'] === 1,
        );
    }

    /**
     * Does this hook fire on a page? `$type` is the content row's type
     * (post/page), `$slug` its PRIMARY slug — translated slugs differ per
     * locale, so matching the source slug keeps one rule consistent across
     * every language variant.
     */
    public function appliesTo(string $type, string $slug): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->target === 'posts' && $type !== 'post') {
            return false;
        }
        if ($this->target === 'pages' && $type !== 'page') {
            return false;
        }
        $slug = strtolower($slug);
        if (\in_array($slug, $this->excludeSlugs, true)) {
            return false;
        }

        return $this->includeSlugs === [] || \in_array($slug, $this->includeSlugs, true);
    }

    /**
     * The data-cta-location the injected block carries so GTM reports can
     * tell "after first paragraph CTA" from "bottom CTA": the hook's label
     * snake_cased, falling back to the position (hook_after_paragraph_2).
     */
    public function locationLabel(): string
    {
        $slug = Slugger::slugify($this->label);
        if ($slug !== 'untitled') {
            return str_replace('-', '_', $slug);
        }
        $position = $this->position === 'after_paragraph'
            ? 'after_paragraph_' . $this->paragraph
            : $this->position;

        return 'hook_' . $position;
    }
}
