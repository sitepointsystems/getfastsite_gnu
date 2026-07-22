<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

final class JobContext
{
    /**
     * @param \Closure(): void $heartbeat
     */
    public function __construct(private readonly \Closure $heartbeat)
    {
    }

    /** Tells the runner this job is still alive (self-healing watchdog). */
    public function heartbeat(): void
    {
        ($this->heartbeat)();
    }
}
