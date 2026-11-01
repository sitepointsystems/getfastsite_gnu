<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Forms: the one interactive thing a static site needs and cannot do itself.
 *
 * A form is a site-level DEFINITION (fields, recipients, subject). Content
 * embeds it by handle with `<div data-fs-form="contact"></div>` and the build
 * expands that into real markup — the same placeholder shape components use,
 * and for the same reason: ContentSanitizer strips `<form>`, `<input>` and
 * `<button>` from body HTML, so hand-authored form markup silently vanishes.
 * An inert div survives sanitizing untouched.
 *
 * `recipients` lives HERE and is never read from the submitted request. A form
 * that lets the poster choose the destination is an open relay, and the
 * account it would be relaying through belongs to the customer.
 *
 * Submissions are stored BEFORE any send is attempted, and that ordering is
 * the point: SMTP is configured per site and can be wrong, expired, or blocked
 * by a provider that turned off basic auth. Storing first turns a total outage
 * into a delayed notification instead of a lead that never existed.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(<<<'SQL'
            CREATE TABLE forms (
                id INTEGER PRIMARY KEY,
                handle TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL DEFAULT '',
                recipients TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '',
                fields TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(fields)),
                submit_label TEXT NOT NULL DEFAULT '',
                success_path TEXT NOT NULL DEFAULT '',
                success_message TEXT NOT NULL DEFAULT '',
                consent_text TEXT NOT NULL DEFAULT '',
                autoresponder_enabled INTEGER NOT NULL DEFAULT 0,
                autoresponder_subject TEXT NOT NULL DEFAULT '',
                autoresponder_body TEXT NOT NULL DEFAULT '',
                store_submissions INTEGER NOT NULL DEFAULT 1,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            ) STRICT
            SQL);

        $db->executeStatement(<<<'SQL'
            CREATE TABLE form_submissions (
                id INTEGER PRIMARY KEY,
                form_id INTEGER NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                locale TEXT NOT NULL DEFAULT '',
                page_path TEXT NOT NULL DEFAULT '',
                payload TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(payload)),
                reply_to TEXT NOT NULL DEFAULT '',
                ip_hash TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'sent', 'failed', 'spam')),
                attempts INTEGER NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '',
                trace TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                sent_at TEXT
            ) STRICT
            SQL);

        $db->executeStatement('CREATE INDEX ix_form_submissions_form ON form_submissions (form_id, id DESC)');
        $db->executeStatement('CREATE INDEX ix_form_submissions_status ON form_submissions (status, id)');
    }
};
