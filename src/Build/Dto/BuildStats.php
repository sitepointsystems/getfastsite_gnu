<?php

declare(strict_types=1);

namespace Fastsite\Build\Dto;

final readonly class BuildStats
{
    public function __construct(
        public int $pages,
        public int $written,
        public int $skipped,
        public int $deleted,
        public int $bytes,
        public float $durationMs,
        public string $outputDir,
    ) {
    }
}
