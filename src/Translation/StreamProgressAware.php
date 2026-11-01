<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * A translator whose long streaming calls can report liveness while bytes
 * arrive. The queue reclaims a running job when its heartbeat goes stale
 * (600s) — without mid-stream ticks, a single healthy-but-slow generation
 * longer than that would be falsely reclaimed, which is why the stream
 * ceiling used to be a hard 420s and big pages could NEVER finish
 * ("Operation timed out after 420001 milliseconds with 91590 bytes
 * received"). With ticks flowing, the ceiling can be generous and the
 * low-speed abort alone guards against dead wires.
 */
interface StreamProgressAware
{
    /**
     * $tick is invoked periodically while a streaming response is being
     * received (rate-limited by the implementation). Pass null to detach.
     */
    public function onStreamProgress(?\Closure $tick): void;
}
