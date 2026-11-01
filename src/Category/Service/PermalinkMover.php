<?php

declare(strict_types=1);

namespace Fastsite\Category\Service;

use Fastsite\Category\Repository\CategoryRepository;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\Repository\TranslationRepository;

/**
 * Records the 301s for every live URL a permalink change moves.
 *
 * A slug edit has always left a redirect behind (ContentService). Three newer
 * changes move URLs the same way and must do the same, or they silently break
 * every inbound link and every indexed result:
 *
 * - the site's post pattern changes (`/blog/{slug}/` → `/{category}/{slug}/`),
 * - its category archive pattern or page size changes,
 * - a category is renamed while its posts carry `{category}` in their URL.
 *
 * Only PUBLISHED posts are moved — a draft never had a URL. Post moves cover
 * every locale (a translated post lives at its own slug under the locale
 * prefix and moves with the pattern exactly like the primary does). Archive
 * moves cover the primary locale only: a secondary locale's archive exists
 * only while some post in it is translated, and that is not worth a query per
 * category per locale for a rule that, at worst, leaves a translated archive
 * page to the 404.
 *
 * Every move goes through `ContentRepository::recordSlugChange`, so chains are
 * flattened and a path that is live again loses its old rule, the same as for
 * a slug edit.
 */
final class PermalinkMover
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly CategoryRepository $categories,
        private readonly TranslationRepository $translations,
    ) {
    }

    /**
     * Everything a site-settings change moved. Returns how many redirects were
     * recorded, so a caller can say so.
     */
    public function siteChanged(Site $before, Site $after): int
    {
        $moves = [];
        if ($before->postPathPattern !== $after->postPathPattern) {
            $moves = [...$moves, ...$this->postMoves($before, $after, null, null, null)];
        }
        if ($before->categoryPathPattern !== $after->categoryPathPattern || $before->archivePerPage !== $after->archivePerPage) {
            $moves = [...$moves, ...$this->archiveMoves($before, $after, null, null)];
        }

        return $this->record($after, $moves);
    }

    /** A category's slug changed: its archive moves, and so do its posts when their URL carries it. */
    public function categoryRenamed(Site $site, int $categoryId, string $oldSlug, string $newSlug): int
    {
        $moves = $this->archiveMoves($site, $site, $categoryId, [$oldSlug, $newSlug]);
        if (str_contains($site->postPathPattern, '{category}')) {
            $moves = [...$moves, ...$this->postMoves($site, $site, $categoryId, $oldSlug, $newSlug)];
        }

        return $this->record($site, $moves);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function postMoves(Site $before, Site $after, ?int $categoryId, ?string $oldCategory, ?string $newCategory): array
    {
        $rows = array_values(array_filter(
            $this->content->pathRows($after->handle, $categoryId),
            static fn (array $r): bool => $r['type'] === 'post' && $r['status'] === 'published',
        ));
        if ($rows === []) {
            return [];
        }

        $moves = [];
        $slugSets = ['' => null];
        foreach ($after->locales as $locale) {
            $slugSets[$locale] = $this->translations->usableSlugsByLocale($after->handle, $locale);
        }
        foreach ($rows as $row) {
            $catBefore = $oldCategory ?? $row['category_slug'];
            $catAfter = $newCategory ?? $row['category_slug'];
            foreach ($slugSets as $locale => $slugs) {
                $slug = $locale === '' ? $row['slug'] : ($slugs[$row['id']] ?? null);
                if ($slug === null) {
                    continue;
                }
                $prefix = $locale === '' ? '' : '/' . $locale;
                $moves[] = [
                    $prefix . SiteLinks::applyPattern($before->postPathPattern, $slug, $catBefore),
                    $prefix . SiteLinks::applyPattern($after->postPathPattern, $slug, $catAfter),
                ];
            }
        }

        return $moves;
    }

    /**
     * @param array{0: string, 1: string}|null $rename old and new slug of the one category moving
     * @return list<array{0: string, 1: string}>
     */
    private function archiveMoves(Site $before, Site $after, ?int $onlyCategoryId, ?array $rename): array
    {
        $counts = $this->categories->publishedPostCounts($after->handle);
        $moves = [];
        foreach ($counts as $id => $n) {
            if ($onlyCategoryId !== null && $id !== $onlyCategoryId) {
                continue;
            }
            $category = $this->categories->findById($after->handle, $id);
            if ($category === null) {
                continue;
            }
            [$oldSlug, $newSlug] = $rename ?? [$category->slug, $category->slug];
            $oldPages = (int) ceil($n / max(1, $before->archivePerPage));
            $newPages = (int) ceil($n / max(1, $after->archivePerPage));
            for ($page = 1; $page <= $oldPages; ++$page) {
                // A page that no longer exists (the archive got shorter) goes
                // to page 1 of the same archive rather than to a 404.
                $moves[] = [
                    SiteLinks::categoryPath($before, $oldSlug, $page),
                    SiteLinks::categoryPath($after, $newSlug, $page <= $newPages ? $page : 1),
                ];
            }
        }

        return $moves;
    }

    /**
     * @param list<array{0: string, 1: string}> $moves
     */
    private function record(Site $site, array $moves): int
    {
        $n = 0;
        foreach ($moves as [$from, $to]) {
            if ($from === $to) {
                continue;
            }
            $this->content->recordSlugChange($site->handle, $from, $to);
            ++$n;
        }

        return $n;
    }
}
