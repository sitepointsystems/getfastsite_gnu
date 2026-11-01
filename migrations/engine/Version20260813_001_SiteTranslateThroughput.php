<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site translation throughput knobs.
 *
 * Every field of every (content × locale) pair is an independent model call,
 * and they used to run strictly one at a time — an 82-page × 9-locale site is
 * over a thousand calls whose wall clock is simply their sum, which is days.
 * `translate_concurrency` is how many may be in flight at once; it is per site
 * because the ceiling is the site's OWN AI account, and one customer's backlog
 * must never spend another's rate limit. 1 restores the serial behaviour.
 *
 * `translate_chunk_size` is how many items a queued chunk attempts before
 * re-enqueueing itself at the back of the translate lane (the fairness valve
 * that stops one site monopolising the worker). With concurrency it can be
 * larger than the old fixed 10 without hurting anyone else's wait.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN translate_concurrency INTEGER NOT NULL DEFAULT 4
             CHECK (translate_concurrency BETWEEN 1 AND 16)',
        );
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN translate_chunk_size INTEGER NOT NULL DEFAULT 10
             CHECK (translate_chunk_size BETWEEN 1 AND 200)',
        );
    }
};
