<?php

declare(strict_types=1);

namespace Fastsite\Category\Dto;

/**
 * A post category: a name, the slug its archive and (with `{category}` in the
 * post pattern) its posts' URLs are built from, and the archive's own SEO.
 */
final readonly class Category
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $descriptionHtml,
        public string $seoTitle,
        public string $seoDescription,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            descriptionHtml: (string) $row['description_html'],
            seoTitle: (string) $row['seo_title'],
            seoDescription: (string) $row['seo_description'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
