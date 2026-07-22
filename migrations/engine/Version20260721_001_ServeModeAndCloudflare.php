<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site serving mode + Cloudflare Pages auto-setup fields.
 *
 * serve_mode: 'git' (default — push the build to a repo a CDN watches) or
 * 'direct' (the engine itself serves build/ by Host header; no repo needed).
 *
 * cf_api_token_enc: a Cloudflare API token (TokenCipher-encrypted — replayable
 * outbound secret) that lets the engine create the Pages project, attach the
 * custom domain and add DNS via the CF API instead of walking the user through
 * the dashboard. cf_account_id caches the account discovered from that token.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN serve_mode TEXT NOT NULL DEFAULT 'git'
             CHECK (serve_mode IN ('git', 'direct'))",
        );
        $db->executeStatement('ALTER TABLE sites ADD COLUMN cf_api_token_enc TEXT');
        $db->executeStatement('ALTER TABLE sites ADD COLUMN cf_account_id TEXT');
    }
};
