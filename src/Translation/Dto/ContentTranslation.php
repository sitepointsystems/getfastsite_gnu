<?php

declare(strict_types=1);

namespace Fastsite\Translation\Dto;

final readonly class ContentTranslation
{
    public function __construct(
        public int $id,
        public int $contentId,
        public string $locale,
        public string $slug,
        public string $title,
        public string $excerpt,
        public string $bodyHtml,
        public string $seoTitle,
        public string $seoDescription,
        public string $sourceHash,
        public string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            contentId: (int) $row['content_id'],
            locale: (string) $row['locale'],
            slug: (string) $row['slug'],
            title: (string) $row['title'],
            excerpt: (string) $row['excerpt'],
            bodyHtml: (string) $row['body_html'],
            seoTitle: (string) $row['seo_title'],
            seoDescription: (string) $row['seo_description'],
            sourceHash: (string) $row['source_hash'],
            status: (string) $row['status'],
        );
    }
}
