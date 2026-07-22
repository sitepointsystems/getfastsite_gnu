<?php

declare(strict_types=1);

namespace Fastsite\Site\Dto;

final readonly class SiteScript
{
    public function __construct(
        public int $id,
        public string $label,
        public string $location,
        public string $strategy,
        public int $priority,
        public string $code,
        public bool $enabled,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            location: (string) $row['location'],
            strategy: (string) $row['strategy'],
            priority: (int) $row['priority'],
            code: (string) $row['code'],
            enabled: (int) $row['enabled'] === 1,
        );
    }
}
