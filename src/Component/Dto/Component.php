<?php

declare(strict_types=1);

namespace Fastsite\Component\Dto;

final readonly class Component
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public int $id,
        public string $handle,
        public string $type,
        public string $sourceCode,
        public string $css,
        public string $sourceHash,
        public string $bundleHash,
        public string $status,
        public array $warnings,
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
            handle: (string) $row['handle'],
            type: (string) $row['type'],
            sourceCode: (string) $row['source_code'],
            css: (string) $row['css'],
            sourceHash: (string) $row['source_hash'],
            bundleHash: (string) $row['bundle_hash'],
            status: (string) $row['status'],
            warnings: $warnings,
        );
    }
}
