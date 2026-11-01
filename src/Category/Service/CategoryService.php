<?php

declare(strict_types=1);

namespace Fastsite\Category\Service;

use Fastsite\Category\Dto\Category;
use Fastsite\Category\Repository\CategoryRepository;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\Slugger;
use Fastsite\Site\Dto\Site;

/**
 * Every category write goes through here: slug rules, the archive's sanitized
 * description, the redirects a rename leaves behind, and the republish.
 *
 * A category write must publish EAGERLY. It changes archive pages and, with
 * `{category}` in the post pattern, post URLs — but it touches no content row,
 * so the scheduler's reconciler (which watches `content.updated_at`) never
 * sees it. Same reason a component edit republishes itself.
 */
final class CategoryService
{
    private const MAX_NAME = 200;
    private const MAX_SEO_TITLE = 500;
    private const MAX_SEO_DESCRIPTION = 1000;
    private const MAX_DESCRIPTION = 20000;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ContentRepository $content,
        private readonly ContentSanitizer $sanitizer,
        private readonly PermalinkMover $permalinks,
        // Optional so test fixtures can build the service without a queue;
        // php-di does not autowire defaulted params, so it is wired in
        // config/services.php and asserted by ContainerWiringTest.
        private readonly ?\Fastsite\Build\Service\PublishTrigger $publish = null,
    ) {
    }

    /** @return list<Category> */
    public function list(Site $site): array
    {
        return $this->categories->all($site->handle);
    }

    public function find(Site $site, string $slug): ?Category
    {
        return $this->categories->findBySlug($site->handle, $slug);
    }

    /**
     * Create the category at $slug, or update it. On update a `slug` in the
     * input RENAMES it, which moves its archive and (with `{category}` in the
     * post pattern) its posts — both get 301s.
     *
     * @param array<string, mixed> $input name, slug, description_html, seo_title, seo_description
     * @return array{category: Category, created: bool, redirects: int}
     */
    public function upsert(Site $site, string $slug, array $input): array
    {
        $slug = self::normalizeSlug($slug);
        $existing = $this->categories->findBySlug($site->handle, $slug);

        $fields = [];
        if (\array_key_exists('name', $input) || $existing === null) {
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($input['name'] ?? '')));
            if ($name === '') {
                throw new \InvalidArgumentException('A category needs a name.');
            }
            self::assertLength('name', $name, self::MAX_NAME);
            $fields['name'] = $name;
        }
        if (\array_key_exists('description_html', $input)) {
            $raw = trim((string) $input['description_html']);
            self::assertLength('description_html', $raw, self::MAX_DESCRIPTION);
            $fields['description_html'] = $raw === '' ? '' : $this->sanitizer->sanitize($raw)->html;
        }
        if (\array_key_exists('seo_title', $input)) {
            $fields['seo_title'] = trim((string) $input['seo_title']);
            self::assertLength('seo_title', $fields['seo_title'], self::MAX_SEO_TITLE);
        }
        if (\array_key_exists('seo_description', $input)) {
            $fields['seo_description'] = trim((string) $input['seo_description']);
            self::assertLength('seo_description', $fields['seo_description'], self::MAX_SEO_DESCRIPTION);
        }

        $newSlug = isset($input['slug']) && trim((string) $input['slug']) !== '' ? self::normalizeSlug((string) $input['slug']) : $slug;

        if ($existing === null) {
            $id = $this->categories->insert($site->handle, ['slug' => $newSlug] + $fields);
            $category = $this->categories->findById($site->handle, $id);
            \assert($category !== null);
            $this->publish($site, 'category ' . $newSlug . ' created');

            return ['category' => $category, 'created' => true, 'redirects' => 0];
        }

        $redirects = 0;
        if ($newSlug !== $existing->slug) {
            if ($this->categories->findBySlug($site->handle, $newSlug) !== null) {
                throw new \InvalidArgumentException(sprintf('A category with the slug "%s" already exists.', $newSlug));
            }
            $fields['slug'] = $newSlug;
        }

        if ($fields !== []) {
            $this->categories->update($site->handle, $existing->id, $fields);
            if (isset($fields['slug'])) {
                $redirects = $this->permalinks->categoryRenamed($site, $existing->id, $existing->slug, $newSlug);
            }
            $this->publish($site, 'category ' . $newSlug . ' updated');
        }

        $category = $this->categories->findById($site->handle, $existing->id);
        \assert($category !== null);

        return ['category' => $category, 'created' => false, 'redirects' => $redirects];
    }

    /**
     * Refused while any live post (draft or published) is in it: deleting it
     * would move every one of those posts' URLs, and a draft about to be
     * published would quietly lose its category. Move them first.
     */
    public function delete(Site $site, string $slug): void
    {
        $category = $this->categories->findBySlug($site->handle, self::normalizeSlug($slug))
            ?? throw new \RuntimeException(sprintf('No category "%s" on this site.', $slug));

        $used = $this->content->countInCategory($site->handle, $category->id);
        if ($used > 0) {
            throw new \InvalidArgumentException(sprintf(
                'Category "%s" still has %d post%s. Move %s to another category first; deleting it would change %s URL.',
                $category->slug,
                $used,
                $used === 1 ? '' : 's',
                $used === 1 ? 'it' : 'them',
                $used === 1 ? 'its' : 'their',
            ));
        }

        $this->categories->delete($site->handle, $category->id);
        $this->publish($site, 'category ' . $category->slug . ' deleted');
    }

    /**
     * Resolves a `category` value from any ingest path to a category id.
     * Accepts the slug (what agents and REST callers send) or the numeric id
     * (what the dashboard select posts). null / '' / 0 = no category. An
     * unknown value is REJECTED with the slugs that do exist, never silently
     * dropped: a post saved without its category lands at a different URL.
     */
    public function resolve(Site $site, mixed $value): ?int
    {
        return self::resolveWith($this->categories, $site, $value);
    }

    /** {@see resolve}, for a caller holding only the repository (ContentService). */
    public static function resolveWith(CategoryRepository $categories, Site $site, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $category = \is_int($value) || ctype_digit((string) $value)
            ? $categories->findById($site->handle, (int) $value)
            : $categories->findBySlug($site->handle, Slugger::slugify((string) $value));
        if ($category !== null) {
            return $category->id;
        }

        $known = array_map(static fn (Category $c): string => $c->slug, $categories->all($site->handle));
        throw new \InvalidArgumentException(sprintf(
            'Unknown category "%s". %s',
            (string) $value,
            $known === [] ? 'This site has no categories yet; create one first.' : 'Existing: ' . implode(', ', $known) . '.',
        ));
    }

    /** One URL segment: a category slug can never carry a slash. */
    public static function normalizeSlug(string $slug): string
    {
        if (str_contains(trim($slug, '/'), '/')) {
            throw new \InvalidArgumentException('A category slug is one URL segment; it cannot contain "/".');
        }
        $normalized = Slugger::slugify(trim($slug, '/'));
        if ($normalized === 'untitled' && trim($slug) === '') {
            throw new \InvalidArgumentException('A category needs a slug.');
        }

        return $normalized;
    }

    private function publish(Site $site, string $why): void
    {
        $this->publish?->trigger($site, 'publish: ' . $why, 'category');
    }

    private static function assertLength(string $field, string $value, int $max): void
    {
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('%s exceeds the maximum length of %d characters', $field, $max));
        }
    }
}
