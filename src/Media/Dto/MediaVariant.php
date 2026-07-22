<?php

declare(strict_types=1);

namespace Fastsite\Media\Dto;

final readonly class MediaVariant
{
    public function __construct(
        public string $purpose,
        public string $format,
        public int $width,
        public int $height,
        public string $diskPath,
        public int $bytes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            purpose: (string) $row['purpose'],
            format: (string) $row['format'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            diskPath: (string) $row['disk_path'],
            bytes: (int) $row['bytes'],
        );
    }
}
