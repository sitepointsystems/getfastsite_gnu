<?php

declare(strict_types=1);

namespace Fastsite\Form\Service;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\Dto\FormField;
use Fastsite\Form\Dto\Submission;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Form definitions and the submissions they collect.
 *
 * Validation lives here rather than in the handler because three transports
 * reach it (the public POST, the dashboard test, the REST surface) and a rule
 * enforced in only one of them is not a rule.
 */
final class FormService
{
    /** A single-line answer. Anything longer is a paste, not an answer. */
    private const MAX_VALUE = 2000;

    /** A message body. Past this it is a payload, not a message. */
    private const MAX_TEXTAREA = 10_000;

    /** Newest-first submissions kept per form; older rows are pruned. */
    private const KEEP_SUBMISSIONS = 5000;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<Form>
     */
    public function list(string $handle, bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM forms';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY handle';

        try {
            return array_map(Form::fromRow(...), $this->databases->site($handle)->fetchAllAssociative($sql));
        } catch (\Throwable) {
            // A site whose db predates the migration must not break a build.
            return [];
        }
    }

    public function find(string $handle, string $formHandle): ?Form
    {
        try {
            $row = $this->databases->site($handle)
                ->fetchAssociative('SELECT * FROM forms WHERE handle = ?', [$formHandle]);
        } catch (\Throwable) {
            return null;
        }

        return $row === false ? null : Form::fromRow($row);
    }

    public function findById(string $handle, int $id): ?Form
    {
        $row = $this->databases->site($handle)->fetchAssociative('SELECT * FROM forms WHERE id = ?', [$id]);

        return $row === false ? null : Form::fromRow($row);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(string $handle, array $input): Form
    {
        $formHandle = FormField::slug((string) ($input['handle'] ?? ''));
        if ($formHandle === '') {
            throw new \InvalidArgumentException('A form needs a handle.');
        }

        $db = $this->databases->site($handle);
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0
            ? $db->fetchOne('SELECT id FROM forms WHERE id = ?', [$id])
            : $db->fetchOne('SELECT id FROM forms WHERE handle = ?', [$formHandle]);
        $creating = $existing === false;

        // AN UPDATE IS A PATCH, NOT A REPLACE.
        //
        // This used to rebuild the whole row from `$input`, so every key the
        // caller left out was written back as its default: a REST or MCP call
        // that changed one boolean silently blanked the form's name, subject,
        // submit label and autoresponder copy. That is the same wound
        // `upsert_component` opened by wiping stored CSS when the key was
        // omitted — and it is worse here, because the dashboard always sends
        // everything, so it only ever bites the API caller, quietly.
        //
        // Now: on CREATE the defaults below apply; on UPDATE a field is
        // touched only when the caller actually sent its key.
        $row = ['updated_at' => $this->clock->iso()];
        $put = function (string $column, string $key, callable $clean) use (&$row, $input, $creating): void {
            if (\array_key_exists($key, $input)) {
                $row[$column] = $clean($input[$key]);
            } elseif ($creating) {
                $row[$column] = $clean(null);
            }
        };

        // Recipients and fields are what make a form a form, so they are
        // REQUIRED on create and merely validated-if-sent on update.
        if ($creating || \array_key_exists('recipients', $input)) {
            $recipients = Form::splitAddresses((string) ($input['recipients'] ?? ''));
            foreach ($recipients as $recipient) {
                if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('Not a valid recipient address: ' . $recipient);
                }
            }
            if ($recipients === []) {
                throw new \InvalidArgumentException('A form needs at least one recipient address.');
            }
            $row['recipients'] = implode(', ', $recipients);
        }

        if ($creating || \array_key_exists('fields', $input)) {
            $fields = $this->normaliseFields($input['fields'] ?? []);
            if ($fields === []) {
                throw new \InvalidArgumentException('A form needs at least one field.');
            }
            $fieldsJson = json_encode(array_map(static fn (FormField $f): array => $f->toArray(), $fields));
            \assert($fieldsJson !== false);
            $row['fields'] = $fieldsJson;
        }

        $row['handle'] = $formHandle;
        $put('name', 'name', static fn ($v): string => mb_substr(trim((string) $v), 0, 120));
        $put('subject', 'subject', static fn ($v): string => mb_substr(trim((string) $v), 0, 200));
        $put('submit_label', 'submit_label', static fn ($v): string => mb_substr(trim((string) $v), 0, 80));
        $put('success_path', 'success_path', fn ($v): string => $this->normalisePath((string) $v));
        $put('success_message', 'success_message', static fn ($v): string => mb_substr(trim((string) $v), 0, 500));
        $put('consent_text', 'consent_text', static fn ($v): string => mb_substr(trim((string) $v), 0, 500));
        $put('autoresponder_subject', 'autoresponder_subject', static fn ($v): string => mb_substr(trim((string) $v), 0, 200));
        $put('autoresponder_body', 'autoresponder_body', static fn ($v): string => mb_substr(trim((string) $v), 0, 5000));
        $put('autoresponder_enabled', 'autoresponder_enabled', fn ($v): int => $this->flag($v));
        $put('turnstile', 'turnstile', fn ($v): int => $this->flag($v));
        // These two default to ON at creation rather than off, so the `null`
        // the closure sees on create has to become 1, not 0.
        $put('enabled', 'enabled', fn ($v): int => $v === null ? 1 : $this->flag($v));
        $put('store_submissions', 'store_submissions', fn ($v): int => $v === null ? 1 : $this->flag($v));

        if (\array_key_exists('messages', $input)) {
            $messages = json_encode(\Fastsite\Form\FormMessages::sanitizeOverrides($input['messages']));
            \assert($messages !== false);
            $row['messages'] = $messages;
        }

        if (!$creating) {
            $id = (int) $existing;
            $db->update('forms', $row, ['id' => $id]);
        } else {
            $row['created_at'] = $this->clock->iso();
            $db->insert('forms', $row);
            $id = (int) $db->lastInsertId();
        }

        $saved = $db->fetchAssociative('SELECT * FROM forms WHERE id = ?', [$id]);
        \assert($saved !== false);

        return Form::fromRow($saved);
    }

    public function delete(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('DELETE FROM forms WHERE id = ?', [$id]);
    }

    /**
     * @param mixed $raw
     * @return list<FormField>
     */
    private function normaliseFields(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $fields = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = FormField::fromArray($item);
            // Two fields with one name means the second silently overwrites the
            // first in the payload — a data-loss bug that only shows up in the
            // notification, so reject it at save time instead.
            if (isset($seen[$field->name])) {
                continue;
            }
            $seen[$field->name] = true;
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Validates a submitted payload against the definition.
     *
     * $locale picks the language the errors come back in — this is the
     * no-JavaScript path, and answering a Danish site's visitor in English is
     * worse than either language alone.
     *
     * @param array<string, mixed> $input raw request body
     * @return array{values: array<string, string>, errors: list<string>, spam: bool}
     */
    public function validate(Form $form, array $input, string $locale = 'en'): array
    {
        $msg = \Fastsite\Form\FormMessages::for($locale, $form->messages);

        // The honeypot: a real field, positioned off-screen and marked
        // tabindex="-1" + autocomplete="off", that no human ever sees or
        // focuses. Bots fill every input they find. Detected here, reported
        // as success to the caller — telling a bot it was caught just teaches
        // it which field to skip next time.
        if (trim((string) ($input[FormRenderer::HONEYPOT] ?? '')) !== '') {
            return ['values' => [], 'errors' => [], 'spam' => true];
        }

        $values = [];
        $errors = [];

        foreach ($form->fields as $field) {
            $raw = $input[$field->name] ?? '';
            $value = \is_scalar($raw) ? trim((string) $raw) : '';
            $value = str_replace("\0", '', $value);
            $limit = $field->type === 'textarea' ? self::MAX_TEXTAREA : self::MAX_VALUE;
            $value = mb_substr($value, 0, $limit);

            if ($field->type === 'checkbox') {
                $checked = $value !== '' && $value !== '0' && strtolower($value) !== 'false';
                if ($field->required && !$checked) {
                    $errors[] = $msg->get('required', ['field' => $field->label]);
                }
                $values[$field->name] = $checked ? 'yes' : 'no';
                continue;
            }

            if ($value === '') {
                if ($field->required) {
                    $errors[] = $msg->get('required', ['field' => $field->label]);
                }
                $values[$field->name] = '';
                continue;
            }

            if ($field->type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = $msg->get('email', ['field' => $field->label]);
            }
            if ($field->type === 'url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = $msg->get('url', ['field' => $field->label]);
            }
            if ($field->type === 'number' && !is_numeric($value)) {
                $errors[] = $msg->get('number', ['field' => $field->label]);
            }
            // A select must answer with one of its own options — otherwise the
            // "choose one" constraint exists only in the browser, and the
            // stored value is whatever the poster typed.
            if ($field->type === 'select' && $field->options !== [] && !\in_array($value, $field->options, true)) {
                $errors[] = $msg->get('choice', ['field' => $field->label]);
            }

            $values[$field->name] = $value;
        }

        if ($form->consentText !== '') {
            $consent = (string) ($input[FormRenderer::CONSENT] ?? '');
            if ($consent === '' || $consent === '0') {
                $errors[] = $msg->get('consent', ['consent' => $form->consentText]);
            }
        }

        return ['values' => $values, 'errors' => $errors, 'spam' => false];
    }

    /**
     * Stores a validated submission.
     *
     * @param array<string, string> $values
     * @param array{locale?: string, page?: string, ip?: string, ua?: string} $meta
     */
    public function record(
        string $handle,
        Form $form,
        array $values,
        array $meta = [],
        ?\Fastsite\Form\Spam\SpamVerdict $verdict = null,
        bool $hold = false,
    ): Submission {
        $replyField = $form->replyToField();
        $replyTo = $replyField !== null ? ($values[$replyField->name] ?? '') : '';

        $payload = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        \assert($payload !== false);

        $db = $this->databases->site($handle);
        $db->insert('form_submissions', [
            'form_id' => $form->id,
            'locale' => mb_substr((string) ($meta['locale'] ?? ''), 0, 16),
            'page_path' => mb_substr((string) ($meta['page'] ?? ''), 0, 500),
            'payload' => $payload,
            'reply_to' => filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : '',
            // The raw IP is personal data we have no reason to keep. A salted
            // hash still answers the only question we ask of it — "is this the
            // same submitter as a moment ago?" — without storing the address.
            'ip_hash' => $this->hashIp((string) ($meta['ip'] ?? '')),
            'user_agent' => mb_substr((string) ($meta['ua'] ?? ''), 0, 300),
            // `hold` and the verdict are separate on purpose. In log mode a
            // submission carries a spam score and its reasons and is still
            // delivered — that is the whole point of log mode, and collapsing
            // the two would make it unimplementable.
            'status' => $hold ? 'spam' : 'pending',
            'spam_score' => $verdict?->score ?? 0,
            'spam_reasons' => $verdict?->reasonsJson() ?? '[]',
            'attempts' => 0,
            'error' => '',
            'trace' => '',
            'created_at' => $this->clock->iso(),
        ]);

        $id = (int) $db->lastInsertId();
        $this->prune($handle, $form->id);

        $row = $db->fetchAssociative('SELECT * FROM form_submissions WHERE id = ?', [$id]);
        \assert($row !== false);

        return Submission::fromRow($row);
    }

    public function findSubmission(string $handle, int $id): ?Submission
    {
        $row = $this->databases->site($handle)
            ->fetchAssociative('SELECT * FROM form_submissions WHERE id = ?', [$id]);

        return $row === false ? null : Submission::fromRow($row);
    }

    /**
     * @param ?string $status `inbox` = everything the owner actually wants to
     *                        see (all but spam), `spam` = the spam folder, a
     *                        literal status = just that one, null = all.
     * @return list<Submission>
     */
    public function submissions(
        string $handle,
        ?int $formId = null,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
    ): array {
        $sql = 'SELECT * FROM form_submissions';
        $where = [];
        $params = [];
        if ($formId !== null) {
            $where[] = 'form_id = ?';
            $params[] = $formId;
        }
        if ($status === 'inbox') {
            $where[] = "status != 'spam'";
        } elseif ($status !== null && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        try {
            return array_map(
                Submission::fromRow(...),
                $this->databases->site($handle)->fetchAllAssociative($sql, $params),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function markSent(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement(
            'UPDATE form_submissions SET status = ?, sent_at = ?, error = ?, attempts = attempts + 1 WHERE id = ?',
            ['sent', $this->clock->iso(), '', $id],
        );
    }

    public function markFailed(string $handle, int $id, string $error, string $trace = ''): void
    {
        $this->databases->site($handle)->executeStatement(
            'UPDATE form_submissions SET status = ?, error = ?, trace = ?, attempts = attempts + 1 WHERE id = ?',
            ['failed', mb_substr($error, 0, 2000), mb_substr($trace, 0, 8000), $id],
        );
    }

    /**
     * Puts failed sends back in the queue — the recovery path after fixing an
     * SMTP setting. The submissions were never lost, only undelivered, so this
     * is what turns "the mail config was wrong for a week" into a caught-up
     * inbox rather than a week of leads nobody ever saw.
     *
     * @return list<int> submission ids to enqueue
     */
    public function retryFailed(string $handle, ?int $formId = null): array
    {
        $db = $this->databases->site($handle);
        $sql = "SELECT id FROM form_submissions WHERE status = 'failed'";
        $params = [];
        if ($formId !== null) {
            $sql .= ' AND form_id = ?';
            $params[] = $formId;
        }

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $db->fetchFirstColumn($sql . ' ORDER BY id', $params));
        if ($ids === []) {
            return [];
        }

        // Only the array parameter needs a type; DBAL infers the rest, and a
        // types array keyed by position is what it expects.
        $db->executeStatement(
            'UPDATE form_submissions SET status = ?, error = ?, trace = ? WHERE id IN (?)',
            ['pending', '', '', $ids],
            [3 => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return $ids;
    }

    public function markSpam(string $handle, int $id): void
    {
        $this->databases->site($handle)
            ->executeStatement('UPDATE form_submissions SET status = ? WHERE id = ?', ['spam', $id]);
    }

    /**
     * Moves submissions INTO the spam folder by hand — the judgement the
     * scorer could not make.
     *
     * This exists because a human reviewing a batch and concluding "these are
     * all bots" is the most reliable classifier there is, and until now there
     * was no way to act on it: `markSpam()` was written when the table was
     * created and no surface ever called it, so the only thing an owner could
     * do with confirmed spam was leave it sitting in the inbox forever.
     *
     * Narrowing by `$status` is what makes it safe to use in bulk — "every
     * submission that failed to send" is a set someone can actually check,
     * where "every submission" is not.
     *
     * @return list<int> the ids moved
     */
    public function markSpamWhere(
        string $handle,
        ?int $id = null,
        ?int $formId = null,
        ?string $status = null,
    ): array {
        $db = $this->databases->site($handle);
        $sql = "SELECT id FROM form_submissions WHERE status != 'spam'";
        $params = [];
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }
        if ($formId !== null) {
            $sql .= ' AND form_id = ?';
            $params[] = $formId;
        }
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $db->fetchFirstColumn($sql . ' ORDER BY id', $params));
        if ($ids === []) {
            return [];
        }

        $db->executeStatement(
            'UPDATE form_submissions SET status = ? WHERE id IN (?)',
            ['spam', $ids],
            [1 => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return $ids;
    }

    /**
     * Takes a submission back out of the spam folder and queues its
     * notification — the correction path for a false positive.
     *
     * Returns the ids the caller must enqueue, the same contract retryFailed
     * uses, so the queue stays the handler's business and this stays testable
     * without one.
     *
     * @return list<int>
     */
    public function releaseSpam(string $handle, ?int $id = null, ?int $formId = null): array
    {
        $db = $this->databases->site($handle);
        $sql = "SELECT id FROM form_submissions WHERE status = 'spam'";
        $params = [];
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }
        if ($formId !== null) {
            $sql .= ' AND form_id = ?';
            $params[] = $formId;
        }

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $db->fetchFirstColumn($sql . ' ORDER BY id', $params));
        if ($ids === []) {
            return [];
        }

        // The score and reasons are KEPT. A released submission is the most
        // valuable row in the table — it is a labelled false positive, and
        // wiping the verdict destroys the only evidence of why the scorer got
        // it wrong.
        $db->executeStatement(
            'UPDATE form_submissions SET status = ?, error = ?, trace = ? WHERE id IN (?)',
            ['pending', '', '', $ids],
            [3 => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return $ids;
    }

    /**
     * Deletes flagged submissions older than the retention window.
     *
     * This is what stops the spam folder becoming another thing nobody empties
     * — but it deliberately does not run at flag time. A scoring bug in its
     * first week should leave something behind to look at, and the difference
     * between "held for thirty days then deleted" and "deleted on sight" is
     * the difference between a recoverable mistake and an invisible one.
     *
     * `$days = 0` means delete everything currently flagged, which is the
     * available-but-not-default answer for a site that has seen enough.
     *
     * @return int rows deleted
     */
    public function purgeSpam(string $handle, int $days = 30): int
    {
        $cutoff = $days <= 0
            ? $this->clock->iso()
            : gmdate('Y-m-d\TH:i:s\Z', strtotime($this->clock->iso()) - ($days * 86400));

        try {
            return (int) $this->databases->site($handle)->executeStatement(
                "DELETE FROM form_submissions WHERE status = 'spam' AND created_at <= ?",
                [$cutoff],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Re-runs the scorer over stored submissions WITHOUT changing any of them,
     * unless `$apply` says otherwise.
     *
     * This exists because the only honest way to calibrate a spam filter is
     * against submissions a human has already judged. It is the difference
     * between "I think these weights are right" and "these weights catch 37 of
     * the 39 you told me were spam, and flag none of the ones you answered".
     *
     * @param callable(Form, array<string, string>, string): \Fastsite\Form\Spam\SpamVerdict $scorer
     * @return list<array{id: int, status: string, score: int, reasons: list<string>, would_hold: bool}>
     */
    public function rescore(string $handle, callable $scorer, ?int $formId = null, bool $apply = false): array
    {
        $db = $this->databases->site($handle);
        $forms = [];
        foreach ($this->list($handle) as $form) {
            $forms[$form->id] = $form;
        }

        $out = [];
        foreach ($this->submissions($handle, $formId, 500, 0, 'all') as $submission) {
            $form = $forms[$submission->formId] ?? null;
            if ($form === null) {
                continue;
            }

            $verdict = $scorer($form, $submission->payload, $submission->locale ?: 'en');
            $out[] = [
                'id' => $submission->id,
                'status' => $submission->status,
                'score' => $verdict->score,
                'reasons' => $verdict->reasons,
                'would_hold' => $verdict->isSpam,
            ];

            if (!$apply) {
                continue;
            }

            // Applying only ever moves rows INTO the spam folder. A stored
            // submission that a human already answered must not be dragged
            // back out of "sent" by a scorer that happens to disagree.
            $db->executeStatement(
                'UPDATE form_submissions SET spam_score = ?, spam_reasons = ?, status = ? WHERE id = ?',
                [
                    $verdict->score,
                    $verdict->reasonsJson(),
                    ($verdict->isSpam && $submission->status !== 'sent') ? 'spam' : $submission->status,
                    $submission->id,
                ],
            );
        }

        return $out;
    }

    /**
     * Per-form counts for the dashboard.
     *
     * @return array<int, array{total: int, pending: int, failed: int, spam: int}>
     */
    public function counts(string $handle): array
    {
        try {
            $rows = $this->databases->site($handle)->fetchAllAssociative(
                "SELECT form_id,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN status = 'spam' THEN 1 ELSE 0 END) AS spam
                 FROM form_submissions GROUP BY form_id",
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['form_id']] = [
                'total' => (int) $row['total'],
                'pending' => (int) $row['pending'],
                'failed' => (int) $row['failed'],
                'spam' => (int) $row['spam'],
            ];
        }

        return $out;
    }

    /**
     * Every enabled form's shape, for the preview staleness signature. Forms
     * live in the site db, so without this an edited form keeps rendering its
     * old markup in preview — the same bug class the CLAUDE.md preview section
     * documents three earlier instances of.
     */
    public function signature(string $handle): string
    {
        $parts = [];
        foreach ($this->list($handle, true) as $form) {
            $fields = json_encode(array_map(static fn (FormField $f): array => $f->toArray(), $form->fields));
            // `turnstile` belongs here for the documented reason every other
            // entry does: it changes the rendered markup (and the page's CSP),
            // but it moves no content timestamp, so without it preview would
            // keep serving the pre-challenge tree — a fifth instance of the
            // preview-staleness bug CLAUDE.md already records four of.
            $parts[] = $form->handle . ':' . $form->submitLabel . ':' . $form->successPath
                . ':' . $form->consentText . ':' . ($form->turnstile ? '1' : '0') . ':' . (string) $fields;
        }

        return $parts === [] ? '' : md5(implode('|', $parts));
    }

    /**
     * A success path has to be a path on the site's own tree. Storing an
     * absolute URL here would make the post-submit redirect an open redirect
     * with the customer's domain as the bait.
     */
    private function normalisePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value) === 1 || str_starts_with($value, '//')) {
            throw new \InvalidArgumentException('The success page must be a path on this site, not a full URL.');
        }

        return '/' . ltrim(mb_substr($value, 0, 300), '/');
    }

    private function flag(mixed $value): int
    {
        return ($value && $value !== '0' && $value !== 'false') ? 1 : 0;
    }

    private function hashIp(string $ip): string
    {
        return $ip === '' ? '' : substr(hash('sha256', 'fsform|' . $ip), 0, 32);
    }

    /** Keeps the newest rows per form so a hammered form cannot fill the disk. */
    private function prune(string $handle, int $formId): void
    {
        $this->databases->site($handle)->executeStatement(
            'DELETE FROM form_submissions WHERE form_id = ? AND id NOT IN (
                 SELECT id FROM form_submissions WHERE form_id = ? ORDER BY id DESC LIMIT ?
             )',
            [$formId, $formId, self::KEEP_SUBMISSIONS],
        );
    }
}
