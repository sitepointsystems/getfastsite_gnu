<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

final class JobContext
{
    /**
     * @param \Closure(): void $heartbeat
     * @param \Closure(string): void|null $progress
     */
    public function __construct(
        private readonly \Closure $heartbeat,
        private readonly ?\Closure $progress = null,
    ) {
    }

    /** Tells the runner this job is still alive (self-healing watchdog). */
    public function heartbeat(): void
    {
        ($this->heartbeat)();
    }

    /**
     * Records a human-readable phase note ("Optimizing image 12/87…") shown
     * live on /admin/ops. Also counts as a heartbeat.
     */
    public function progress(string $note): void
    {
        $this->progress !== null ? ($this->progress)($note) : ($this->heartbeat)();
    }
}
