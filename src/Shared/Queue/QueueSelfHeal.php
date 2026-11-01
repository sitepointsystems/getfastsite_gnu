<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

use Predis\Client;
use Psr\Log\LoggerInterface;

/**
 * Web-layer fallback for the queue's background self-healing. Normally the
 * workers and schedule:run sweep stale jobs and promote delayed ones — but
 * a real incident showed php-fpm serving traffic for hours while EVERY
 * background process was wedged, so zombies piled up with nobody to sweep
 * them. Dashboard/MCP requests now opportunistically run the same repairs:
 * at most one request per window wins a Redis mutex and pays a few
 * milliseconds; everything is guarded so a broken Redis or DB can never
 * take a user request down with it.
 */
final class QueueSelfHeal
{
    private const MUTEX_KEY = 'fastsite:selfheal:web';
    private const EVERY_SECONDS = 120;

    public function __construct(
        private readonly JobRunner $runner,
        private readonly JobQueue $queue,
        private readonly Client $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function maybeHeal(): void
    {
        try {
            // SET NX EX: only the window-winning request heals.
            if ($this->redis->set(self::MUTEX_KEY, '1', 'EX', self::EVERY_SECONDS, 'NX') === null) {
                return;
            }

            $reclaimed = $this->runner->sweepStaleJobs(force: true);
            $promoted = $this->queue->promoteDelayed();
            if ($reclaimed > 0 || $promoted > 0) {
                $this->logger->warning('Web-request self-heal acted — are the background processes healthy?', [
                    'reclaimed' => $reclaimed,
                    'promoted' => $promoted,
                ]);
            }
        } catch (\Throwable $e) {
            // Healing is best-effort; a user request must never pay for it.
            try {
                $this->logger->debug('Web self-heal skipped', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
                // even logging must not throw into the request
            }
        }
    }
}
