<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * A dedicated translation model per AI key.
 *
 * Translation is by far the highest-VOLUME AI call the engine makes — an
 * 82-page × 9-locale site is well over a thousand calls — and it is the one
 * that least needs a frontier model: it rewrites existing prose into another
 * language. Sharing the general `model` column meant translating with whatever
 * was chosen for chat/auto-correct, which defaults to Claude Sonnet: expensive
 * per call and, on a per-account rate limit, the thing that parks the whole run.
 *
 * Empty means "use the general model", so existing keys keep behaving exactly
 * as they did until someone picks something.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement("ALTER TABLE ai_credentials ADD COLUMN translate_model TEXT NOT NULL DEFAULT ''");
    }
};
