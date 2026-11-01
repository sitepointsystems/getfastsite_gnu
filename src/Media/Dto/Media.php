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
        public ?string $optimizedAt = null,
        public int $revision = 0,
        /**
         * Non-destructive crop, as FRACTIONS of the original (0..1). The
         * uploaded file is never modified — sharp applies this every time
         * variants are built, so a crop is re-drawable and clearable forever.
         * Fractions rather than pixels so nothing outside sharp needs to know
         * the original's post-rotation geometry.
         */
        public ?float $cropX = null,
        public ?float $cropY = null,
        public ?float $cropW = null,
        public ?float $cropH = null,
        /** Target width in pixels, applied AFTER the crop. Never enlarges. */
        public ?int $resizeWidth = null,
    ) {
    }

    /**
     * The transform fragment the sharp worker takes, or null when this image
     * is untransformed (every row is, until someone crops one).
     *
     * @return array{crop: array{x: float, y: float, w: float, h: float}|null, width: int|null}|null
     */
    public function sharpTransform(): ?array
    {
        // Locals so the null checks actually narrow the types below.
        $w = $this->cropW;
        $h = $this->cropH;
        $width = ($this->resizeWidth !== null && $this->resizeWidth > 0) ? $this->resizeWidth : null;
        $hasCrop = $w !== null && $h !== null && $w > 0.0 && $h > 0.0;
        if (!$hasCrop && $width === null) {
            return null;
        }

        return [
            'crop' => ($w !== null && $h !== null && $hasCrop)
                ? ['x' => $this->cropX ?? 0.0, 'y' => $this->cropY ?? 0.0, 'w' => $w, 'h' => $h]
                : null,
            'width' => $width,
        ];
    }

    /**
     * The slug the BUILD emits — the stored slug plus the revision from the
     * first replace onward.
     *
     * /media/* and /files/* are served `immutable` for a year, so replacing an
     * item's content behind an unchanged URL would be invisible to everyone
     * who had already loaded it. The id and original_name stay put (embeds
     * resolve through the database), and the URL they resolve to moves.
     * Revision 0 keeps the original filename, so nothing already published
     * changes until it is actually replaced.
     */
    public function publicSlug(): string
    {
        return $this->revision > 0 ? $this->slug . '-r' . $this->revision : $this->slug;
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
            optimizedAt: ($row['optimized_at'] ?? null) !== null ? (string) $row['optimized_at'] : null,
            revision: (int) ($row['revision'] ?? 0),
            cropX: ($row['crop_x'] ?? null) !== null ? (float) $row['crop_x'] : null,
            cropY: ($row['crop_y'] ?? null) !== null ? (float) $row['crop_y'] : null,
            cropW: ($row['crop_w'] ?? null) !== null ? (float) $row['crop_w'] : null,
            cropH: ($row['crop_h'] ?? null) !== null ? (float) $row['crop_h'] : null,
            resizeWidth: ($row['resize_width'] ?? null) !== null ? (int) $row['resize_width'] : null,
        );
    }
}
