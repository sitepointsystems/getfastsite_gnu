<?php

declare(strict_types=1);

namespace Fastsite\Content\Dto;

final readonly class Content
{
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
        public ?string $jsonLd,
        public string $contentHash,
        public array $warnings,
        public ?string $scheduledAt,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
    ) {
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
            jsonLd: $row['json_ld'] !== null ? (string) $row['json_ld'] : null,
            contentHash: (string) $row['content_hash'],
            warnings: $warnings,
            scheduledAt: $row['scheduled_at'] !== null ? (string) $row['scheduled_at'] : null,
            publishedAt: $row['published_at'] !== null ? (string) $row['published_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: ($row['deleted_at'] ?? null) !== null ? (string) $row['deleted_at'] : null,
        );
    }
}
