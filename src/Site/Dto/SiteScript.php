<?php

declare(strict_types=1);

namespace Fastsite\Site\Dto;

final readonly class SiteScript
{
    /**
     * Which pages a script may land on. Identical to
     * `ComponentHook::TARGETS` on purpose — one targeting vocabulary in the
     * product rather than two that drift apart.
     */
    public const TARGETS = ['all', 'posts', 'pages'];

    /**
     * @param list<string> $includeSlugs empty = every page the target matches
     * @param list<string> $excludeSlugs always wins over include/target
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $location,
        public string $strategy,
        public int $priority,
        public string $code,
        public bool $enabled,
        public string $target = 'all',
        public array $includeSlugs = [],
        public array $excludeSlugs = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            location: (string) $row['location'],
            strategy: (string) $row['strategy'],
            priority: (int) $row['priority'],
            code: (string) $row['code'],
            enabled: (int) $row['enabled'] === 1,
            target: (string) ($row['target'] ?? 'all') ?: 'all',
            includeSlugs: self::slugs($row['include_slugs'] ?? '[]'),
            excludeSlugs: self::slugs($row['exclude_slugs'] ?? '[]'),
        );
    }

    /**
     * Does this script belong on a page?
     *
     * Deliberately the same shape as `ComponentHook::appliesTo()`, including
     * the order — enabled, then type, then EXCLUDE, then include — so exclude
     * wins unconditionally over both the include list and the target.
     *
     * `$type` is the content row's type (`post`/`page`) and `$slug` its
     * PRIMARY slug: translated slugs differ per locale, so matching the source
     * slug keeps one rule consistent across every language variant.
     *
     * THE PAGES WITH NO CONTENT ROW MATTER HERE, and they work without a
     * special case. The blog index comes in as `index`/`blog` and an error
     * page as `error`/`404`; `posts` and `pages` reject an unknown type by
     * their negative arms, and `all` falls through both. So `target: all`
     * keeps meaning *every* page — analytics does not silently miss the blog
     * index, which is the sort of gap nobody notices for a quarter — while
     * `exclude_slugs: blog` is how you keep a tag off it.
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

    /** True when this script lands on every page the site builds. */
    public function isSiteWide(): bool
    {
        return $this->target === 'all' && $this->includeSlugs === [] && $this->excludeSlugs === [];
    }

    /**
     * @return list<string>
     */
    private static function slugs(mixed $raw): array
    {
        $decoded = json_decode(\is_string($raw) ? $raw : '[]', true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($s): string => \is_scalar($s) ? (string) $s : '', $decoded),
            static fn (string $s): bool => $s !== '',
        ));
    }
}
