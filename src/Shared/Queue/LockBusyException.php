<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

/**
 * The per-site lock is held by another job. The runner requeues with a
 * short delay and does NOT count an attempt.
 */
final class LockBusyException extends \RuntimeException
{
}
