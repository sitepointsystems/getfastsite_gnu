<?php

declare(strict_types=1);

namespace Fastsite\Media\Dto;

final readonly class Media
{
    /**
     * @param list<MediaVariant> $variants
     */
    public function __construct(
        public int $id,
        public string $kind,
        public string $originalName,
        public string $slug,
        public string $ext,
        public string $mime,
        public string $sha256,
        public int $bytes,
        public ?int $width,
        public ?int $height,
        public string $alt,
        public bool $decorative,
        public string $storage,
        public string $diskPath,
        public string $status,
        public array $variants = [],
        public ?string $deletedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<MediaVariant> $variants
     */
    public static function fromRow(array $row, array $variants = []): self
    {
        return new self(
            id: (int) $row['id'],
            kind: (string) $row['kind'],
            originalName: (string) $row['original_name'],
            slug: (string) $row['slug'],
            ext: (string) $row['ext'],
            mime: (string) $row['mime'],
            sha256: (string) $row['sha256'],
            bytes: (int) $row['bytes'],
            width: $row['width'] !== null ? (int) $row['width'] : null,
            height: $row['height'] !== null ? (int) $row['height'] : null,
            alt: (string) $row['alt'],
            decorative: (int) $row['decorative'] === 1,
            storage: (string) $row['storage'],
            diskPath: (string) $row['disk_path'],
            status: (string) $row['status'],
            variants: $variants,
            deletedAt: ($row['deleted_at'] ?? null) !== null ? (string) $row['deleted_at'] : null,
        );
    }
}
