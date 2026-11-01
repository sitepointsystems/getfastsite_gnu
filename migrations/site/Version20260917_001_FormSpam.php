<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Spam scoring on submissions, and a per-form Turnstile opt-in.
 *
 * The `spam` status has existed in `form_submissions`' CHECK constraint since
 * the table was created, and `FormService::markSpam()` has existed to set it —
 * but nothing on any surface ever called that method. The bucket was built and
 * never wired to anything, so every submission that got past the honeypot was
 * emailed, and the only two outcomes were "sent" and "failed".
 *
 * That is what these columns fix. A scored submission is STORED like any other
 * and simply never notified, which keeps the guarantee the whole feature rests
 * on — the row is written before a send is attempted, so a lead is never lost
 * — while the inbox only sees what scored clean. A false positive then costs a
 * glance at the spam folder, not a customer.
 *
 * `spam_score` and `spam_reasons` are kept on EVERY row, not just flagged
 * ones, because the scores of the submissions that passed are the only way to
 * tell a threshold that is working from one that is about to start eating real
 * leads. They are also what makes log-only mode (`sites.spam_mode = 'log'`)
 * possible: score and record, notify anyway, decide later.
 *
 * `forms.turnstile` is per form rather than per site on purpose. Turnstile
 * costs the page JavaScript and flips its CSP, so it is the escalation for a
 * form actually under attack — not something a site turns on everywhere and
 * silently pays for on pages nobody is attacking.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE form_submissions ADD COLUMN spam_score INTEGER NOT NULL DEFAULT 0');
        $db->executeStatement(
            "ALTER TABLE form_submissions ADD COLUMN spam_reasons TEXT NOT NULL DEFAULT '[]'",
        );
        $db->executeStatement('ALTER TABLE forms ADD COLUMN turnstile INTEGER NOT NULL DEFAULT 0');

        // The spam folder is read by status and ordered newest-first, and the
        // retention purge sweeps by status + age. The existing (status, id)
        // index answers the first; this one answers the second without a scan
        // over a form that has collected thousands of rows.
        $db->executeStatement(
            'CREATE INDEX ix_form_submissions_spam ON form_submissions (status, created_at)',
        );
    }
};
