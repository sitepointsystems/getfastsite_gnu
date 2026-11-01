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
        public ?string $intro = null,
        public ?string $tldr = null,
        public ?string $conclusion = null,
        /**
         * Why the LAST attempt on this row failed, or null. Present on failed
         * rows AND on still-live 'translated' rows whose refresh failed — the
         * sync uses it to sort known-bad work to the back of a chunk.
         */
        public ?string $error = null,
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
            intro: ($row['intro'] ?? null) !== null ? (string) $row['intro'] : null,
            tldr: ($row['tldr'] ?? null) !== null ? (string) $row['tldr'] : null,
            conclusion: ($row['conclusion'] ?? null) !== null ? (string) $row['conclusion'] : null,
            error: ($row['error'] ?? null) !== null && (string) $row['error'] !== '' ? (string) $row['error'] : null,
        );
    }
}
