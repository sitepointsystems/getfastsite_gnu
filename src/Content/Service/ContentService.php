<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
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
    ];

    public function __construct(
        private readonly ContentRepository $repository,
        private readonly ContentSanitizer $sanitizer,
        private readonly Clock $clock,
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
        $slug = $this->uniqueSlug($site->handle, $type, (string) ($input['slug'] ?? '') ?: Slugger::slugify($title), self::reservedPageSlugs($site));

        $clean = $this->prepareBody((string) ($input['body_html'] ?? ''), ($input['sanitize'] ?? true) !== false);
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
            'featured_media_id' => isset($input['featured_media_id']) ? (int) $input['featured_media_id'] : null,
            'author_name' => $authorName,
            'source' => $source,
            'source_ref' => $sourceRef,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'content_hash' => self::hash($title, $excerpt, $clean->html, $seoTitle, $seoDescription),
            'warnings' => json_encode($clean->warnings, JSON_THROW_ON_ERROR),
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
        if (isset($input['body_html'])) {
            $clean = $this->prepareBody((string) $input['body_html'], ($input['sanitize'] ?? true) !== false);
            $fields['body_html'] = $clean->html;
            $fields['warnings'] = json_encode($clean->warnings, JSON_THROW_ON_ERROR);
        }
        if (isset($input['author_name'])) {
            $author = (string) $input['author_name'];
            self::assertLength('author_name', $author);
            $fields['author_name'] = $author;
        }
        if (\array_key_exists('featured_media_id', $input)) {
            $fields['featured_media_id'] = $input['featured_media_id'] !== null ? (int) $input['featured_media_id'] : null;
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

        if (isset($input['slug'])) {
            if (trim((string) $input['slug']) === '') {
                throw new \InvalidArgumentException('slug cannot be empty');
            }
            $newSlug = Slugger::slugifyPath((string) $input['slug']);
            if ($newSlug !== $existing->slug) {
                $fields['slug'] = $this->uniqueSlug($site->handle, $existing->type, $newSlug, self::reservedPageSlugs($site));
                $this->repository->recordSlugChange(
                    $site->handle,
                    $this->pathFor($site, $existing->type, $existing->slug),
                    $this->pathFor($site, $existing->type, $fields['slug']),
                );
            }
        }

        if ($fields === []) {
            return $existing;
        }

        $title = $fields['title'] ?? $existing->title;
        $excerpt = $fields['excerpt'] ?? $existing->excerpt;
        $body = $fields['body_html'] ?? $existing->bodyHtml;
        $seoTitle = $fields['seo_title'] ?? $existing->seoTitle;
        $seoDescription = $fields['seo_description'] ?? $existing->seoDescription;
        $fields['content_hash'] = self::hash($title, $excerpt, $body, $seoTitle, $seoDescription);

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
    }

    /**
     * @return list<Content>
     */
    public function listTrashed(Site $site, ?string $type = null): array
    {
        return $this->repository->listTrashed($site->handle, $type);
    }

    public function pathFor(Site $site, string $type, string $slug): string
    {
        $pattern = $type === 'post' ? $site->postPathPattern : $site->pagePathPattern;

        return str_replace('{slug}', $slug, $pattern);
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
            '<div data-fs-embed>' . $bodyHtml . '</div>',
            ['Stored verbatim (sanitization off) — trusted content, exempt from the zero-JS/PageSpeed checks.'],
        );
    }

    private function uniqueSlug(string $site, string $type, string $slug, array $reservedPageSlugs = self::RESERVED_PAGE_SLUGS): string
    {
        $slug = Slugger::slugifyPath($slug);
        $candidate = $slug;
        $n = 2;
        while (
            ($type === 'page' && \in_array($candidate, $reservedPageSlugs, true))
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

    private static function assertLength(string $field, string $value): void
    {
        $max = self::MAX_LEN[$field] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('%s exceeds the maximum length of %d characters', $field, $max));
        }
    }

    private static function hash(string ...$parts): string
    {
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
