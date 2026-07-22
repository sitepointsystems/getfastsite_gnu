<?php

declare(strict_types=1);

namespace Fastsite\Shared\Clock;

interface Clock
{
    public function now(): \DateTimeImmutable;

    /** ISO-8601 UTC, e.g. 2026-07-18T14:03:07Z — the format every DB column uses. */
    public function iso(): string;
}
