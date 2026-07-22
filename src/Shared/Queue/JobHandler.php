<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

interface JobHandler
{
    /** Job type this handler executes (e.g. "build_full"). */
    public static function type(): string;

    /**
     * Executes the job. Throw to fail (retried with backoff up to
     * max_attempts); throw LockBusyException to requeue WITHOUT burning an
     * attempt. Call $context->heartbeat() between long phases.
     */
    public function run(Job $job, JobContext $context): void;
}
