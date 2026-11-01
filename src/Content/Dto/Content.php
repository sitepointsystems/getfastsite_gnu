<?php

declare(strict_types=1);

namespace Fastsite\Content\Dto;

final readonly class Content
{
    /**
     * The HTTP statuses a row may claim as its error page.
     *
     * 4xx only, and a curated list rather than "anything 400-499": the engine
     * emits a file for each of these, but WHICH ONES ACTUALLY SERVE depends on
     * the serving mode — all of them on Apache (`ErrorDocument` per code), 404
     * and 410 in direct mode (the only 4xx `DirectServeGate` produces), and
     * **404 alone on Cloudflare Pages**, which picks it up by its own
     * convention and has no mechanism for the rest. A free numeric field would
     * invite pages that look configured and can never once be served.
     *
     * @var list<int>
     */
    public const CODES = [401, 403, 404, 410, 429, 451];

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $slug,
        public string $status,
        public string $title,
        public string $excerpt,
        public string $bodyHtml,
        public ?int $featuredMediaId,
        public string $authorName,
        public string $source,
        public ?string $sourceRef,
        public string $seoTitle,
        public string $seoDescription,
        public bool $noindex,
        public bool $nofollow,
        public ?string $jsonLd,
        public string $contentHash,
        public array $warnings,
        public ?string $scheduledAt,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
        public ?string $intro = null,
        public ?string $tldr = null,
        public ?string $conclusion = null,
        /**
         * The HTTP status this row IS the page for, or null for an ordinary
         * page. An error row renders to `/{status}.html` instead of its slug
         * path, and is kept out of the sitemap, llms.txt, the hreflang cluster
         * and every on-site listing.
         */
        public ?int $errorStatus = null,
        /**
         * The post's ONE category (posts only; a page never has one). The slug
         * rides along, resolved by the repository's read, because it is part
         * of the post's URL when the site's post pattern carries `{category}`
         * and SiteLinks has to answer that without a database.
         */
        public ?int $categoryId = null,
        public ?string $categorySlug = null,
    ) {
    }

    /** An error page rather than an ordinary post or page. */
    public function isErrorPage(): bool
    {
        return $this->errorStatus !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var list<string> $warnings */
        $warnings = json_decode((string) $row['warnings'], true) ?: [];

        return new self(
            id: (int) $row['id'],
            type: (string) $row['type'],
            slug: (string) $row['slug'],
            status: (string) $row['status'],
            title: (string) $row['title'],
            excerpt: (string) $row['excerpt'],
            bodyHtml: (string) $row['body_html'],
            featuredMediaId: $row['featured_media_id'] !== null ? (int) $row['featured_media_id'] : null,
            authorName: (string) $row['author_name'],
            source: (string) $row['source'],
            sourceRef: $row['source_ref'] !== null ? (string) $row['source_ref'] : null,
            seoTitle: (string) $row['seo_title'],
            seoDescription: (string) $row['seo_description'],
            noindex: (bool) ($row['noindex'] ?? 0),
            nofollow: (bool) ($row['nofollow'] ?? 0),
            jsonLd: $row['json_ld'] !== null ? (string) $row['json_ld'] : null,
            contentHash: (string) $row['content_hash'],
            warnings: $warnings,
            scheduledAt: $row['scheduled_at'] !== null ? (string) $row['scheduled_at'] : null,
            publishedAt: $row['published_at'] !== null ? (string) $row['published_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: ($row['deleted_at'] ?? null) !== null ? (string) $row['deleted_at'] : null,
            intro: ($row['intro'] ?? null) !== null ? (string) $row['intro'] : null,
            tldr: ($row['tldr'] ?? null) !== null ? (string) $row['tldr'] : null,
            conclusion: ($row['conclusion'] ?? null) !== null ? (string) $row['conclusion'] : null,
            errorStatus: ($row['error_status'] ?? null) !== null ? (int) $row['error_status'] : null,
            categoryId: ($row['category_id'] ?? null) !== null ? (int) $row['category_id'] : null,
            categorySlug: ($row['category_slug'] ?? null) !== null ? (string) $row['category_slug'] : null,
        );
    }
}
