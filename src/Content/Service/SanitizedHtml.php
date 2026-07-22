<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

final readonly class SanitizedHtml
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $html,
        public array $warnings,
    ) {
    }
}
