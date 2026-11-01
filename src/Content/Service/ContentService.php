<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Site\Dto\Site;

/**
 * All content writes go through here — sanitizer, slug uniqueness,
 * content_hash and revision snapshots are invariants the rest of the
 * pipeline (translation staleness, incremental builds) relies on.
 */
final class ContentService
{
    private const TYPES = ['post', 'page'];

    /** Upper bounds on short text fields (body_html is capped by the sanitizer). */
    private const MAX_LEN = [
        'title' => 500,
        'excerpt' => 2000,
        'seo_title' => 500,
        'seo_description' => 1000,
        'author_name' => 200,
        'source' => 64,
        'source_ref' => 512,
        'intro' => 20000,
        'tldr' => 20000,
        'conclusion' => 20000,
    ];

    /** Structured HTML fragments the shell renders as styled boxes. */
    private const FRAGMENTS = ['intro', 'tldr', 'conclusion'];

    /** The wrapper that marks a stored body/fragment as trusted-verbatim. */
    public const VERBATIM_OPEN = '<div data-fs-embed>';
    public const VERBATIM_CLOSE = '</div>';

    public function __construct(
        private readonly ContentRepository $repository,
        private readonly ContentSanitizer $sanitizer,
        private readonly Clock $clock,
        private readonly MediaRepository $media,
        // Only `purge()` uses it, and only because a hard delete is the one
        // content change no level-based reconciler can ever see (below).
        // Optional so every other caller and test fixture is unaffected —
        // which means php-di does NOT autowire it: it is wired explicitly in
        // config/services.php and asserted by ContainerWiringTest.
        private readonly ?\Fastsite\Build\Service\PublishTrigger $publish = null,
        // Resolves the `category` field. Optional for the same fixture reason,
        // and wired + asserted the same way: without it a write that sends a
        // category is refused outright rather than silently saved without one.
        private readonly ?\Fastsite\Category\Repository\CategoryRepository $categories = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(Site $site, array $input, string $actor = 'cli'): Content
    {
        $type = (string) ($input['type'] ?? 'post');
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('type must be one of: ' . implode(', ', self::TYPES));
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }

        $status = (string) ($input['status'] ?? 'draft');
        if (!\in_array($status, ['draft', 'scheduled', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('invalid status: ' . $status);
        }
        if ($status === 'scheduled' && trim((string) ($input['scheduled_at'] ?? '')) === '') {
            throw new \InvalidArgumentException('status "scheduled" requires scheduled_at (ISO-8601 UTC)');
        }

        $scheduledAt = self::isoOrNull((string) ($input['scheduled_at'] ?? ''), 'scheduled_at');
        $publishedAt = self::isoOrNull((string) ($input['published_at'] ?? ''), 'published_at');

        self::assertLength('title', $title);

        $errorStatus = $this->errorStatus($site, $input['error_status'] ?? null, null);

        // An error page's slug does not address it — SiteLinks sends it to
        // /{status}.html — but it still has to be unique, so it defaults to the
        // code rather than to a slugified title that could collide with a real
        // page the moment someone titles their 404 "Page not found".
        $slugInput = (string) ($input['slug'] ?? '');
        if ($slugInput === '') {
            $slugInput = $errorStatus !== null ? (string) $errorStatus : Slugger::slugify($title);
        }
        $categoryId = $this->categoryId($site, $type, $input['category'] ?? null);
        $categorySlug = $categoryId === null ? null : $this->categories?->findById($site->handle, $categoryId)?->slug;
        $slug = $this->uniqueSlug($site->handle, $type, $slugInput, self::reservedSlugsFor($site, $type, $categorySlug));

        $sanitize = self::sanitizeFor($site, $input);
        $clean = $this->prepareBody((string) ($input['body_html'] ?? ''), $sanitize);
        $warnings = $clean->warnings;
        $fragments = [];
        foreach (self::FRAGMENTS as $frag) {
            $fragments[$frag] = $this->prepareFragment($frag, $input[$frag] ?? null, $sanitize, $warnings);
        }
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $seoTitle = trim((string) ($input['seo_title'] ?? ''));
        $seoDescription = trim((string) ($input['seo_description'] ?? ''));
        $authorName = (string) ($input['author_name'] ?? '');
        $source = (string) ($input['source'] ?? 'manual');
        $sourceRef = isset($input['source_ref']) ? (string) $input['source_ref'] : null;

        self::assertLength('excerpt', $excerpt);
        self::assertLength('seo_title', $seoTitle);
        self::assertLength('seo_description', $seoDescription);
        self::assertLength('author_name', $authorName);
        self::assertLength('source', $source);
        if ($sourceRef !== null) {
            self::assertLength('source_ref', $sourceRef);
        }

        $fields = [
            'type' => $type,
            'slug' => $slug,
            'status' => $status,
            'title' => $title,
            'excerpt' => $excerpt,
            'body_html' => $clean->html,
            'featured_media_id' => $this->featuredMediaId($site, $input['featured_media_id'] ?? null),
            'category_id' => $categoryId,
            'author_name' => $authorName,
            'source' => $source,
            'source_ref' => $sourceRef,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'error_status' => $errorStatus,
            // An error page is ALWAYS noindex, whatever the caller asked for.
            // An indexed 404 is a real SEO defect — Google treats a soft 404
            // as a quality signal against the whole site — and until now the
            // engine's 404 got noindex only as a side effect of development
            // mode's blanket sweep. `nofollow` is deliberately NOT forced: the
            // "back to the front page" link on a 404 is exactly the link a
            // crawler that landed there should follow.
            'noindex' => ($errorStatus !== null || self::flag($input['noindex'] ?? false)) ? 1 : 0,
            'nofollow' => self::flag($input['nofollow'] ?? false) ? 1 : 0,
            'json_ld' => self::jsonLd($input['json_ld'] ?? null),
            'intro' => $fragments['intro'],
            'tldr' => $fragments['tldr'],
            'conclusion' => $fragments['conclusion'],
            'content_hash' => self::hash($title, $excerpt, $clean->html, $seoTitle, $seoDescription, $fragments['intro'], $fragments['tldr'], $fragments['conclusion']),
            'warnings' => json_encode($warnings, JSON_THROW_ON_ERROR),
            'scheduled_at' => $scheduledAt,
            // The "Publish date/time" field (scheduled_at) is the authoritative
            // date shown on the post: an immediate publish uses it (else an
            // explicit published_at, else now). A scheduled post gets its
            // published_at from the scheduler when it goes live.
            'published_at' => $status === 'published' ? ($publishedAt ?? $scheduledAt ?? $this->clock->iso()) : null,
        ];

        try {
            $id = $this->repository->insert($site->handle, $fields);
        } catch (UniqueConstraintViolationException) {
            // (source, source_ref) collision — the caller re-sent existing
            // external content. A conflict, not a server error.
            throw new DuplicateContentException(sprintf(
                'Content with source "%s" and source_ref "%s" already exists.',
                $source,
                (string) $sourceRef,
            ));
        }
        $this->snapshot($site->handle, $id, $actor, 'create');

        $content = $this->repository->findById($site->handle, $id);
        \assert($content !== null);

        return $content;
    }

    /**
     * @param array<string, mixed> $input keys absent = unchanged
     */
    public function update(Site $site, int $id, array $input, string $actor = 'cli'): Content
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Content #%d not found on site "%s".', $id, $site->handle));
        }

        $fields = [];

        if (isset($input['title'])) {
            $title = trim((string) $input['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('title cannot be empty');
            }
            self::assertLength('title', $title);
            $fields['title'] = $title;
        }
        if (isset($input['excerpt'])) {
            $fields['excerpt'] = trim((string) $input['excerpt']);
            self::assertLength('excerpt', $fields['excerpt']);
        }
        if (isset($input['seo_title'])) {
            $fields['seo_title'] = trim((string) $input['seo_title']);
            self::assertLength('seo_title', $fields['seo_title']);
        }
        if (isset($input['seo_description'])) {
            $fields['seo_description'] = trim((string) $input['seo_description']);
            self::assertLength('seo_description', $fields['seo_description']);
        }
        // array_key_exists, not isset: sending an explicit null is how a page
        // stops being the error page and becomes an ordinary one again.
        $errorSent = \array_key_exists('error_status', $input);
        $errorStatus = $errorSent ? $this->errorStatus($site, $input['error_status'], $id) : null;
        if ($errorSent) {
            $fields['error_status'] = $errorStatus;
        }
        if (\array_key_exists('noindex', $input)) {
            $fields['noindex'] = self::flag($input['noindex']) ? 1 : 0;
        }
        // Whatever the caller sent, an error page stays noindex — see create().
        // Decided from the status this update LEAVES the row in, so clearing
        // error_status in the same call also releases the noindex lock.
        if ($errorSent ? $errorStatus !== null : $existing->isErrorPage()) {
            $fields['noindex'] = 1;
        }
        if (\array_key_exists('nofollow', $input)) {
            $fields['nofollow'] = self::flag($input['nofollow']) ? 1 : 0;
        }
        // array_key_exists, not isset — an explicit null (or '') clears it.
        if (\array_key_exists('json_ld', $input)) {
            $fields['json_ld'] = self::jsonLd($input['json_ld']);
        }
        if (isset($input['body_html'])) {
            $clean = $this->prepareBody((string) $input['body_html'], self::sanitizeFor($site, $input));
            $fields['body_html'] = $clean->html;
            $fields['warnings'] = json_encode($clean->warnings, JSON_THROW_ON_ERROR);
        }
        $fragWarnings = [];
        foreach (self::FRAGMENTS as $frag) {
            // array_key_exists, not isset — an explicit null (or '') clears the field.
            if (\array_key_exists($frag, $input)) {
                $fields[$frag] = $this->prepareFragment($frag, $input[$frag], self::sanitizeFor($site, $input), $fragWarnings);
            }
        }
        if ($fragWarnings !== []) {
            /** @var list<string> $base */
            $base = isset($fields['warnings'])
                ? (json_decode((string) $fields['warnings'], true) ?: [])
                : $existing->warnings;
            $fields['warnings'] = json_encode(array_merge($base, $fragWarnings), JSON_THROW_ON_ERROR);
        }
        if (isset($input['author_name'])) {
            $author = (string) $input['author_name'];
            self::assertLength('author_name', $author);
            $fields['author_name'] = $author;
        }
        if (\array_key_exists('featured_media_id', $input)) {
            $fields['featured_media_id'] = $this->featuredMediaId($site, $input['featured_media_id']);
        }
        if (isset($input['scheduled_at'])) {
            $fields['scheduled_at'] = self::isoOrNull((string) $input['scheduled_at'], 'scheduled_at');
        }

        $explicitPublishedAt = isset($input['published_at'])
            ? self::isoOrNull((string) $input['published_at'], 'published_at')
            : null;
        if (isset($input['published_at'])) {
            // Allow correcting an already-published date on redelivery.
            $fields['published_at'] = $explicitPublishedAt;
        }

        if (isset($input['status'])) {
            $status = (string) $input['status'];
            if (!\in_array($status, ['draft', 'scheduled', 'published', 'archived'], true)) {
                throw new \InvalidArgumentException('invalid status: ' . $status);
            }
            if ($status === 'scheduled' && ($fields['scheduled_at'] ?? $existing->scheduledAt) === null) {
                throw new \InvalidArgumentException('status "scheduled" requires scheduled_at (ISO-8601 UTC)');
            }
            $fields['status'] = $status;
        }

        // The "Publish date/time" field (scheduled_at) is the authoritative date
        // shown on the post. When the resulting status is published, keep
        // published_at in sync with it (unless an explicit published_at was
        // given): default it on first publish, and update it if the author edits
        // the publish date on an already-published post.
        if (!isset($input['published_at'])) {
            $effectiveStatus = $fields['status'] ?? $existing->status;
            $newScheduled = $fields['scheduled_at'] ?? $existing->scheduledAt;
            if ($effectiveStatus === 'published') {
                if ($existing->publishedAt === null) {
                    $fields['published_at'] = $newScheduled ?? $this->clock->iso();
                } elseif (isset($input['scheduled_at'])) {
                    $fields['published_at'] = $newScheduled ?? $existing->publishedAt;
                }
            }
        }

        // array_key_exists, not isset: an explicit null takes a post out of
        // its category.
        $newCategorySlug = $existing->categorySlug;
        if (\array_key_exists('category', $input)) {
            $categoryId = $this->categoryId($site, $existing->type, $input['category']);
            if ($categoryId !== $existing->categoryId) {
                $fields['category_id'] = $categoryId;
                $newCategorySlug = $categoryId === null ? null : $this->categories?->findById($site->handle, $categoryId)?->slug;
            }
        }

        $newSlug = $existing->slug;
        if (isset($input['slug'])) {
            if (trim((string) $input['slug']) === '') {
                throw new \InvalidArgumentException('slug cannot be empty');
            }
            $candidate = Slugger::slugifyPath((string) $input['slug']);
            if ($candidate !== $existing->slug) {
                $newSlug = $fields['slug'] = $this->uniqueSlug($site->handle, $existing->type, $candidate, self::reservedSlugsFor($site, $existing->type, $newCategorySlug));
            }
        }

        // A slug edit, or a category move while the post pattern carries
        // `{category}`, changes the URL: leave a 301 behind, as a slug edit
        // always has. Compared as PATHS, so a category move on a site whose
        // pattern ignores categories records nothing.
        $oldPath = $this->pathFor($site, $existing->type, $existing->slug, $existing->categorySlug);
        $newPath = $this->pathFor($site, $existing->type, $newSlug, $newCategorySlug);
        if ($oldPath !== $newPath) {
            $this->repository->recordSlugChange($site->handle, $oldPath, $newPath);
        }

        if ($fields === []) {
            return $existing;
        }

        $title = (string) ($fields['title'] ?? $existing->title);
        $excerpt = (string) ($fields['excerpt'] ?? $existing->excerpt);
        $body = (string) ($fields['body_html'] ?? $existing->bodyHtml);
        $seoTitle = (string) ($fields['seo_title'] ?? $existing->seoTitle);
        $seoDescription = (string) ($fields['seo_description'] ?? $existing->seoDescription);
        $nullable = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        $intro = $nullable(\array_key_exists('intro', $fields) ? $fields['intro'] : $existing->intro);
        $tldr = $nullable(\array_key_exists('tldr', $fields) ? $fields['tldr'] : $existing->tldr);
        $conclusion = $nullable(\array_key_exists('conclusion', $fields) ? $fields['conclusion'] : $existing->conclusion);
        $fields['content_hash'] = self::hash($title, $excerpt, $body, $seoTitle, $seoDescription, $intro, $tldr, $conclusion);

        $this->repository->update($site->handle, $id, $fields);
        $this->snapshot($site->handle, $id, $actor, 'update');

        $content = $this->repository->findById($site->handle, $id);
        \assert($content !== null);

        return $content;
    }

    /**
     * Soft-delete: the row keeps its data + revisions but drops out of every
     * read (and the next build removes its page). Reversible via restore().
     */
    public function softDelete(Site $site, int $id, string $actor = 'cli'): Content
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Content #%d not found on site "%s".', $id, $site->handle));
        }

        $this->snapshot($site->handle, $id, $actor, 'delete');
        $this->repository->softDelete($site->handle, $id);

        $trashed = $this->repository->findAnyById($site->handle, $id);
        \assert($trashed !== null);

        return $trashed;
    }

    /**
     * Un-trash. Fails if a live sibling now occupies the same slug (or
     * source_ref) — the partial unique indexes exclude trashed rows.
     */
    public function restore(Site $site, int $id): Content
    {
        $existing = $this->repository->findAnyById($site->handle, $id);
        if ($existing === null || $existing->deletedAt === null) {
            throw new \RuntimeException(sprintf('Trashed content #%d not found on site "%s".', $id, $site->handle));
        }

        try {
            $this->repository->restore($site->handle, $id);
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException(sprintf(
                'Cannot restore #%d — a live %s already uses the slug "%s". Rename or remove it first.',
                $id,
                $existing->type,
                $existing->slug,
            ));
        }

        $content = $this->repository->findById($site->handle, $id);
        \assert($content !== null);

        return $content;
    }

    /** Permanently delete a trashed (or any) item + its revisions. Irreversible. */
    public function purge(Site $site, int $id): void
    {
        $existing = $this->repository->findAnyById($site->handle, $id);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Content #%d not found on site "%s".', $id, $site->handle));
        }

        $this->repository->purge($site->handle, $id);

        // THE ONE CONTENT WRITE THAT HAS TO PUBLISH EAGERLY. Every other one
        // is left to the scheduler's level-based reconciler, which coalesces a
        // burst of API writes into a single build — it compares the newest
        // `content.updated_at` against the last build. A purge deletes the
        // ROW, so the timestamp that would have raised that level is gone with
        // it: the maximum goes DOWN, the reconciler sees nothing to do, and
        // the page stays on the CDN forever. It is undetectable by
        // construction, so it cannot be healed later — it has to be queued
        // here. Dedupe collapses a bulk trash-emptying into one build.
        //
        // Published rows only: purging a draft removes a page that was never
        // live. `deleted_at` deliberately does NOT narrow it — trash-then-purge
        // within the same minute destroys the soft-delete's bumped timestamp
        // before any build has run on it, which is exactly how this was found.
        if ($existing->status === 'published') {
            $this->publish?->trigger($site, 'publish: content #' . $id . ' purged', 'content-purge');
        }
    }

    /**
     * @return list<Content>
     */
    public function listTrashed(Site $site, ?string $type = null): array
    {
        return $this->repository->listTrashed($site->handle, $type);
    }

    /**
     * The slug path a row's redirects are recorded against. Deliberately the
     * raw pattern, not SiteLinks::contentPath: that sends a page slugged
     * `home` to `/`, and a redirect FROM `/` is not what renaming the home
     * page has ever recorded.
     */
    public function pathFor(Site $site, string $type, string $slug, ?string $category = null): string
    {
        return $type === 'post'
            ? \Fastsite\Shared\SiteLinks::applyPattern($site->postPathPattern, $slug, $category)
            : str_replace('{slug}', $slug, $site->pagePathPattern);
    }

    /**
     * The `category` field resolved to an id. Posts only: a page has no
     * category in WordPress either, and accepting one would invent a field the
     * page URL can never use.
     */
    private function categoryId(Site $site, string $type, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if ($type !== 'post') {
            throw new \InvalidArgumentException('Only posts have a category.');
        }
        if ($this->categories === null) {
            throw new \LogicException('Category support is not wired into ContentService.');
        }

        return \Fastsite\Category\Service\CategoryService::resolveWith($this->categories, $site, $value);
    }

    /**
     * Validates an `error_status` and enforces one live row per status.
     *
     * Rejected rather than coerced: a typo'd 4004 silently becoming a 404
     * would overwrite the site's real error page, and an unrecognised code
     * silently becoming null would leave the author looking at a page they
     * believe is their 403. (The opposite choice — `RedirectService` quietly
     * turning any unknown status into a 301 — is survivable there because a
     * redirect still redirects; here it would mean the wrong page or no page.)
     *
     * The uniqueness check lives here rather than in a partial unique index
     * because `content` soft-deletes: a trashed 404 must not block creating
     * its replacement, and a UNIQUE constraint cannot see `deleted_at`.
     *
     * @param ?int $currentId the row being updated, excluded from the
     *                        collision check so re-saving its own status is a
     *                        no-op rather than a conflict
     */
    private function errorStatus(Site $site, mixed $raw, ?int $currentId): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('error_status must be one of: ' . implode(', ', Content::CODES));
        }
        $status = (int) $raw;
        if (!\in_array($status, Content::CODES, true)) {
            throw new \InvalidArgumentException('error_status must be one of: ' . implode(', ', Content::CODES));
        }

        $taken = $this->repository->findByErrorStatus($site->handle, $status);
        if ($taken !== null && $taken->id !== $currentId) {
            throw new \InvalidArgumentException(sprintf(
                'This site already has a %d page (content #%d: "%s"). Edit that one, or clear its status first.',
                $status,
                $taken->id,
                $taken->title,
            ));
        }

        return $status;
    }

    /**
     * Root-level slugs the engine's own output owns — a page slugged "blog"
     * would overwrite blog/index.html.
     */
    public const RESERVED_PAGE_SLUGS = ['blog', 'assets', 'media', 'files'];

    /**
     * Reserved root page slugs for a site: the fixed set plus every locale
     * code (a page slugged "de" would collide with the /de/ locale home).
     * Shared with the translation slug path so the two never drift.
     *
     * @return list<string>
     */
    public static function reservedPageSlugs(Site $site): array
    {
        return array_values(array_unique([...self::RESERVED_PAGE_SLUGS, $site->primaryLocale, ...$site->locales]));
    }

    /**
     * The root slugs this row may not take. Pages always share the root with
     * the engine's own output. A post only does when ITS path lands there —
     * `/{slug}/`, or `/{category}/{slug}/` for a post with no category — and
     * then a post slugged `media` would land on the media directory exactly
     * like a page would. Decided per post, so a ported post with a category
     * keeps its slug byte for byte even if it happens to read `blog`.
     *
     * @return list<string>
     */
    private static function reservedSlugsFor(Site $site, string $type, ?string $categorySlug = null): array
    {
        if ($type === 'page') {
            return self::reservedPageSlugs($site);
        }
        $atRoot = trim(\Fastsite\Shared\SiteLinks::applyPattern($site->postPathPattern, 'x', $categorySlug), '/') === 'x';

        return $atRoot ? self::reservedPageSlugs($site) : [];
    }

    /**
     * @param list<string> $reservedPageSlugs
     */
    /**
     * Prepares body HTML for storage. Sanitized by default; when sanitize is
     * false (a trusted port) the body is kept VERBATIM — inline styles,
     * scripts, iframes and all — wrapped in <div data-fs-embed> so the build
     * exempts it from the zero-JS / PageSpeed audit. Media/component/file
     * expansion still runs on the inner markup.
     */
    private function prepareBody(string $bodyHtml, bool $sanitize): SanitizedHtml
    {
        if ($sanitize) {
            return $this->sanitizer->sanitize($bodyHtml);
        }

        return new SanitizedHtml(
            self::wrapVerbatim($bodyHtml),
            ['Stored verbatim (sanitization off) — trusted content, exempt from the zero-JS/PageSpeed checks.'],
        );
    }

    /**
     * Resolves the trust level for ONE write: an explicit per-write `sanitize`
     * always wins, otherwise the site-wide default applies. A trusted-port
     * site therefore never has to remember the flag, and a normal site keeps
     * sanitizing even if a caller omits it.
     *
     * @param array<string, mixed> $input
     */
    private static function sanitizeFor(Site $site, array $input): bool
    {
        if (\array_key_exists('sanitize', $input) && $input['sanitize'] !== null) {
            return $input['sanitize'] !== false;
        }

        return $site->sanitizeContent;
    }

    /**
     * The stored `<div data-fs-embed>` wrapper IS the verbatim marker — it is
     * what PageAudit depth-strips to exempt trusted regions, and therefore the
     * durable record of "this body was accepted as trusted", readable by any
     * consumer holding only the row (notably TranslationService, which must
     * not re-sanitize a body whose whole point was to skip the allowlist).
     */
    public static function isVerbatim(string $html): bool
    {
        return str_starts_with(ltrim($html), self::VERBATIM_OPEN);
    }

    /**
     * Wraps a verbatim body in exactly ONE marker. Idempotent: an already
     * wrapped body (a translated copy the model echoed the wrapper back in)
     * is unwrapped first, so re-storing can never nest markers.
     */
    public static function wrapVerbatim(string $html): string
    {
        $trimmed = trim($html);
        if (str_starts_with($trimmed, self::VERBATIM_OPEN) && str_ends_with($trimmed, self::VERBATIM_CLOSE)) {
            $trimmed = substr(
                $trimmed,
                \strlen(self::VERBATIM_OPEN),
                -\strlen(self::VERBATIM_CLOSE),
            );
        }

        return self::VERBATIM_OPEN . $trimmed . self::VERBATIM_CLOSE;
    }

    /**
     * Prepares a structured fragment (intro/tldr/conclusion) for storage.
     * Trimmed-empty (or null) stores NULL — the conditional content_hash rule
     * depends on "not provided" being NULL, never ''. Otherwise the fragment
     * follows the body's pipeline under the same sanitize flag; verbatim
     * fragments skip the duplicate "stored verbatim" notice (the body's own
     * warning already records the mode).
     *
     * @param list<string> $warnings appended to in place
     */
    private function prepareFragment(string $field, mixed $value, bool $sanitize, array &$warnings): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        self::assertLength($field, $raw);
        if (!$sanitize) {
            return self::wrapVerbatim($raw);
        }
        $clean = $this->sanitizer->sanitize($raw);
        foreach ($clean->warnings as $warning) {
            $warnings[] = $field . ': ' . $warning;
        }

        return $clean->html;
    }

    private function uniqueSlug(string $site, string $type, string $slug, array $reservedPageSlugs = self::RESERVED_PAGE_SLUGS): string
    {
        $slug = Slugger::slugifyPath($slug);
        $candidate = $slug;
        $n = 2;
        while (
            \in_array($candidate, $reservedPageSlugs, true)
            || $this->repository->slugExists($site, $type, $candidate)
        ) {
            $candidate = $slug . '-' . $n;
            ++$n;
        }

        return $candidate;
    }

    private function snapshot(string $site, int $id, string $actor, string $reason): void
    {
        $content = $this->repository->findById($site, $id);
        \assert($content !== null);

        $this->repository->insertRevision(
            $site,
            $id,
            json_encode(get_object_vars($content), JSON_THROW_ON_ERROR),
            $actor,
            $reason,
        );
    }

    /**
     * Normalizes + validates the featured/LCP image reference. null and 0 both
     * mean "no featured image" (the same clearing contract as the nullable
     * media-id fields on the site row); a real id must resolve to a live
     * image in THIS site's library — a dangling or wrong-kind id renders
     * nothing at build time, and silently accepting one is exactly how a
     * lazy-LCP page stays invisible until Lighthouse says 99.
     */
    private function featuredMediaId(Site $site, mixed $value): ?int
    {
        $id = (int) ($value ?? 0);
        if ($id <= 0) {
            return null;
        }
        $media = $this->media->findById($site->handle, $id);
        if ($media === null || $media->kind !== 'image') {
            throw new \InvalidArgumentException(sprintf(
                'featured_media_id %d does not resolve to an image in this site\'s media library.',
                $id,
            ));
        }

        return $id;
    }

    /** Loose boolean for the robots flags: real bools plus "1"/"true"/"on" (form posts, query strings). */
    private static function flag(mixed $value): bool
    {
        return \is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL) : (bool) $value;
    }

    /**
     * Page-specific structured data authored on the content row — a Product, an
     * Event, a HowTo, a LocalBusiness. It is MERGED INTO the page's schema.org
     * graph at build time rather than replacing it, so the site, page and
     * breadcrumb entities survive; a node declaring its own Article type does
     * replace the engine's generated one (two Articles on a page is worse than
     * either alone). It is never translated — it ships on the primary locale.
     *
     * Accepts a JSON string or an already-decoded array (a JSON REST body and
     * an MCP tool call both hand it over decoded). null / '' / [] clears it.
     * The column carries a json_valid CHECK, so malformed JSON has to be
     * rejected HERE with a message an author can act on, rather than surfacing
     * as a constraint violation from SQLite.
     */
    private static function jsonLd(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $decoded = \is_array($value) ? $value : json_decode((string) $value, true);
        if (!\is_array($decoded) || $decoded === []) {
            throw new \InvalidArgumentException(
                'json_ld must be a JSON object, an array of JSON objects, or a {"@graph":[…]} document.',
            );
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function assertLength(string $field, string $value): void
    {
        $max = self::MAX_LEN[$field] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('%s exceeds the maximum length of %d characters', $field, $max));
        }
    }

    /**
     * content_hash doubles as the translation staleness key
     * (content_translations.source_hash compares against it), so the input
     * set must match what TranslationService translates. The structured
     * fragments are appended CONDITIONALLY: rows where all three are NULL
     * keep the exact hash they had before the fields existed — an
     * unconditional change would mark every stored translation on every
     * multilingual site stale and trigger a mass retranslation.
     */
    private static function hash(
        string $title,
        string $excerpt,
        string $body,
        string $seoTitle,
        string $seoDescription,
        ?string $intro = null,
        ?string $tldr = null,
        ?string $conclusion = null,
    ): string {
        $parts = [$title, $excerpt, $body, $seoTitle, $seoDescription];
        if ($intro !== null || $tldr !== null || $conclusion !== null) {
            $parts[] = $intro ?? '';
            $parts[] = $tldr ?? '';
            $parts[] = $conclusion ?? '';
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Every timestamp column stores exactly `YYYY-MM-DDTHH:MM:SSZ` — string
     * ordering in SQLite depends on it, so garbage in is a hard error.
     */
    private static function isoOrNull(string $value, string $field): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Accept the HTML <input type="datetime-local"> format (no seconds and/or
        // no trailing Z) as UTC, so the dashboard date/time picker round-trips.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00Z';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $value .= 'Z';
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);
        // createFromFormat is lenient (99-99 overflows instead of failing) —
        // require a lossless round-trip so only real timestamps pass.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1
            || $parsed === false
            || $parsed->format('Y-m-d\TH:i:s\Z') !== $value
        ) {
            throw new \InvalidArgumentException($field . ' must be ISO-8601 UTC, e.g. 2026-07-18T14:00:00Z');
        }

        return $value;
    }
}
