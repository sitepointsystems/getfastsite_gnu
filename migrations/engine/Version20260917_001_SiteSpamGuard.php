<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site spam guard settings: the scoring mode, how long flagged
 * submissions are kept, and the Cloudflare Turnstile key pair.
 *
 * `spam_mode` has three values and the middle one is the important one:
 *
 *   off  — score nothing, notify everything (the pre-feature behaviour)
 *   log  — score and record the verdict, but notify anyway
 *   on   — score, and hold anything flagged in the spam folder (default)
 *
 * `log` exists because shipping a spam filter that starts blocking on day one
 * is how you silently eat a real lead and never learn you did. A site can run
 * in log mode for a week, look at what WOULD have been held, and only then
 * turn it on. The cost of that caution is a week of spam in the inbox, which
 * is the cheaper of the two mistakes by a wide margin.
 *
 * `spam_retention_days` is what keeps the folder from becoming another thing
 * nobody empties: flagged rows delete themselves once they are this old. 0
 * means delete on sight, which is available but is not the default, because a
 * scoring bug in its first week should leave a trace rather than a silence.
 *
 * The Turnstile secret follows the `ftp_password_enc` / `smtp_password_enc`
 * convention exactly — TokenCipher-encrypted at rest, blank on save keeps the
 * stored value, `__clear__` wipes it. The site key is public by design (it
 * ships in the rendered page) so it is stored in plain text.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement("ALTER TABLE sites ADD COLUMN spam_mode TEXT NOT NULL DEFAULT 'on'");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN spam_retention_days INTEGER NOT NULL DEFAULT 30');
        $db->executeStatement("ALTER TABLE sites ADD COLUMN turnstile_site_key TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN turnstile_secret_enc TEXT');
    }
};
