<?php

declare(strict_types=1);

namespace Fastsite\Shared\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function iso(): string
    {
        return $this->now()->format('Y-m-d\TH:i:s\Z');
    }
}
