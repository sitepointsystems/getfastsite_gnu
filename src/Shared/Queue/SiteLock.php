<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Predis\Client;

/**
 * Per-site write mutex: fastsite:lock:{site}, SET NX EX. Generic from day
 * one so every write-heavy job class can take it — that is what makes
 * scaling past one worker safe by construction (plan §queue).
 */
final class SiteLock
{
    private const TTL = 900;

    public function __construct(private readonly Client $redis)
    {
    }

    /** Returns a release token, or null when the lock is held elsewhere. */
    public function acquire(string $siteHandle): ?string
    {
        $token = bin2hex(random_bytes(16));
        $result = $this->redis->set(self::key($siteHandle), $token, 'EX', self::TTL, 'NX');

        return $result !== null ? $token : null;
    }

    /** Releases only if we still own the lock (compare-and-delete). */
    public function release(string $siteHandle, string $token): void
    {
        $script = <<<'LUA'
            if redis.call('GET', KEYS[1]) == ARGV[1] then
                return redis.call('DEL', KEYS[1])
            end
            return 0
            LUA;

        $this->redis->eval($script, 1, self::key($siteHandle), $token);
    }

    /**
     * Runs $fn under the site lock; throws LockBusyException when held.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function withLock(string $siteHandle, \Closure $fn)
    {
        $token = $this->acquire($siteHandle);
        if ($token === null) {
            throw new LockBusyException(sprintf('Site "%s" is locked by another job.', $siteHandle));
        }

        try {
            return $fn();
        } finally {
            $this->release($siteHandle, $token);
        }
    }

    private static function key(string $siteHandle): string
    {
        return 'fastsite:lock:' . $siteHandle;
    }
}
