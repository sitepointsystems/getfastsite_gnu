<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Form\Service\FormService;
use Fastsite\Shared\Queue\JobQueue;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/forms/action — save/delete a form, retry failed sends,
 * export submissions.
 *
 * Saving a form republishes: the markup is generated at build time, so an
 * edited field list or button label does not reach the live site until the
 * pages are rendered again. Skipping that is the same "it saved but nothing
 * changed" trap the component edits used to have.
 */
final class FormsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly FormService $forms,
        private readonly PublishTrigger $publish,
        private readonly JobQueue $queue,
        private readonly \Fastsite\Site\Service\SiteService $sites,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $base = '/sites/' . $site->handle . '/forms';
        $action = (string) ($body['action'] ?? '');

        try {
            switch ($action) {
                case 'form-save':
                    $form = $this->forms->save($site->handle, [
                        'id' => (int) ($body['id'] ?? 0),
                        'handle' => (string) ($body['handle'] ?? ''),
                        'name' => (string) ($body['name'] ?? ''),
                        'recipients' => (string) ($body['recipients'] ?? ''),
                        'subject' => (string) ($body['subject'] ?? ''),
                        'submit_label' => (string) ($body['submit_label'] ?? ''),
                        'success_path' => (string) ($body['success_path'] ?? ''),
                        'success_message' => (string) ($body['success_message'] ?? ''),
                        'consent_text' => (string) ($body['consent_text'] ?? ''),
                        'autoresponder_enabled' => $body['autoresponder_enabled'] ?? '0',
                        'autoresponder_subject' => (string) ($body['autoresponder_subject'] ?? ''),
                        'autoresponder_body' => (string) ($body['autoresponder_body'] ?? ''),
                        'enabled' => $body['enabled'] ?? '0',
                        // An unchecked box is simply absent from a POST, so it
                        // is sent explicitly — the challenge must never be
                        // turned off by omission on a form under attack.
                        'turnstile' => $body['turnstile'] ?? '0',
                        'fields' => $this->fieldsFrom($body),
                        // Always sent from this form, so a cleared box really
                        // clears the override and falls back to the built-in
                        // pack rather than looking cleared while staying set.
                        'messages' => \is_array($body['messages'] ?? null) ? $body['messages'] : [],
                    ]);

                    $this->publish->trigger($site, 'publish: form "' . $form->handle . '" saved', 'dashboard');

                    return $this->dash->redirect(
                        $base . '?form=' . rawurlencode($form->handle),
                        'Saved. Embed it with <div data-fs-form="' . $form->handle . '"></div> and republish.',
                    );

                case 'form-delete':
                    $id = (int) ($body['id'] ?? 0);
                    $form = $this->forms->findById($site->handle, $id);
                    if ($form === null) {
                        return $this->dash->redirect($base, '', 'That form no longer exists.');
                    }
                    $this->forms->delete($site->handle, $id);
                    // Any page embedding it now renders nothing there, so the
                    // pages have to be rebuilt or they keep showing a form
                    // whose endpoint 404s.
                    $this->publish->trigger($site, 'publish: form "' . $form->handle . '" deleted', 'dashboard');

                    return $this->dash->redirect($base, 'Form deleted. Pages that embedded it are being rebuilt.');

                case 'retry-failed':
                    $formId = (int) ($body['form_id'] ?? 0);
                    $ids = $this->forms->retryFailed($site->handle, $formId > 0 ? $formId : null);
                    foreach ($ids as $submissionId) {
                        $this->queue->enqueue(
                            type: 'send_form_email',
                            siteHandle: $site->handle,
                            payload: ['submission_id' => $submissionId],
                        );
                    }

                    return $this->dash->redirect(
                        $base . ($formId > 0 ? '?submissions=' . $formId : ''),
                        $ids === []
                            ? 'Nothing to retry — no failed sends.'
                            : \count($ids) . ' submission(s) queued to send again.',
                    );

                case 'spam-save':
                    // Site-level, but it lives on the Forms screen rather than
                    // Settings because this is where you are standing when you
                    // decide the guard is wrong — and a setting you have to go
                    // and find is a setting nobody adjusts.
                    $fields = [
                        'spam_mode' => (string) ($body['spam_mode'] ?? 'on'),
                        'spam_retention_days' => (int) ($body['spam_retention_days'] ?? 30),
                        'turnstile_site_key' => (string) ($body['turnstile_site_key'] ?? ''),
                    ];
                    // Blank keeps the stored secret; the explicit checkbox is
                    // the only thing that wipes it — the ftp/smtp convention.
                    if (($body['turnstile_secret_clear'] ?? '') === '1') {
                        $fields['turnstile_secret'] = '__clear__';
                    } elseif (trim((string) ($body['turnstile_secret'] ?? '')) !== '') {
                        $fields['turnstile_secret'] = (string) $body['turnstile_secret'];
                    }

                    try {
                        $this->sites->update($site->handle, $fields);
                    } catch (\InvalidArgumentException $e) {
                        return $this->dash->redirect($base, '', $e->getMessage());
                    }

                    // The challenge is rendered INTO the page at build time, so
                    // turning keys on or off changes published markup and the
                    // page's CSP. Without the republish the setting is saved
                    // and invisible, which is the preview-staleness trap one
                    // layer up.
                    $this->publish->trigger($site, 'publish: spam settings changed', 'dashboard');

                    return $this->dash->redirect($base, 'Spam settings saved. Pages with a challenge are being rebuilt.');

                case 'release-spam':
                    // The correction path for a false positive: take it out of
                    // the spam folder and actually send the notification.
                    // Per-row (`id`) or the whole folder for one form.
                    $formId = (int) ($body['form_id'] ?? 0);
                    $one = (int) ($body['id'] ?? 0);
                    $ids = $this->forms->releaseSpam(
                        $site->handle,
                        $one > 0 ? $one : null,
                        $formId > 0 ? $formId : null,
                    );
                    foreach ($ids as $submissionId) {
                        $this->queue->enqueue(
                            type: 'send_form_email',
                            siteHandle: $site->handle,
                            payload: ['submission_id' => $submissionId],
                        );
                    }

                    return $this->dash->redirect(
                        $base . ($formId > 0 ? '?submissions=' . $formId . '&folder=spam' : ''),
                        $ids === []
                            ? 'Nothing to release.'
                            : \count($ids) . ' submission(s) released and queued to send.',
                    );

                case 'mark-spam':
                    // The mirror of "not spam": the judgement the scorer could
                    // not make, applied by the person who can.
                    $one = (int) ($body['id'] ?? 0);
                    $formId = (int) ($body['form_id'] ?? 0);
                    $ids = $this->forms->markSpamWhere(
                        $site->handle,
                        $one > 0 ? $one : null,
                        $formId > 0 ? $formId : null,
                        trim((string) ($body['status'] ?? '')) ?: null,
                    );

                    return $this->dash->redirect(
                        $base . ($formId > 0 ? '?submissions=' . $formId : ''),
                        $ids === []
                            ? 'Nothing to mark.'
                            : \count($ids) . ' submission(s) moved to the spam folder.',
                    );

                case 'purge-spam':
                    // Empties the folder NOW rather than waiting out the
                    // retention window. Deliberately a separate, explicit
                    // action — nothing else on this screen deletes anything.
                    $deleted = $this->forms->purgeSpam($site->handle, 0);

                    return $this->dash->redirect(
                        $base . '?folder=spam',
                        $deleted === 0
                            ? 'The spam folder is already empty.'
                            : $deleted . ' held submission(s) deleted.',
                    );

                case 'export':
                    $formId = (int) ($body['form_id'] ?? 0);
                    $form = $this->forms->findById($site->handle, $formId);
                    if ($form === null) {
                        return $this->dash->redirect($base, '', 'Pick a form to export.');
                    }

                    return $this->csv($site->handle, $form);

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }

    /**
     * Field rows arrive as `field[0][name]`, `field[0][type]`… — indexed
     * rather than parallel arrays on purpose: an unchecked checkbox is simply
     * absent from a POST, and with parallel arrays that silently shifts every
     * later row's "required" onto the wrong field.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function fieldsFrom(array $body): array
    {
        $rows = $body['field'] ?? [];
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue; // a blank spare row
            }
            $out[] = [
                'name' => $name,
                'type' => (string) ($row['type'] ?? 'text'),
                'label' => (string) ($row['label'] ?? ''),
                'placeholder' => (string) ($row['placeholder'] ?? ''),
                'required' => ($row['required'] ?? '') !== '',
                'wide' => ($row['wide'] ?? '') !== '',
                'options' => array_map(
                    trim(...),
                    explode(',', (string) ($row['options'] ?? '')),
                ),
            ];
        }

        return $out;
    }

    /**
     * Submissions as CSV. The header row is the form's own field labels, so a
     * spreadsheet reads the way the form does; the meta columns come last.
     */
    private function csv(string $handle, \Fastsite\Form\Dto\Form $form): ResponseInterface
    {
        $stream = fopen('php://temp', 'r+');
        \assert($stream !== false);

        $write = static function ($fh, array $row): void {
            fputcsv($fh, array_map(self::csvCell(...), $row), ',', '"', '');
        };

        $labels = array_map(static fn ($f): string => $f->label, $form->fields);
        $write($stream, [...$labels, 'Submitted', 'Status', 'Page', 'Language']);

        foreach ($this->forms->submissions($handle, $form->id, 500) as $submission) {
            $row = [];
            foreach ($form->fields as $field) {
                $row[] = $submission->payload[$field->name] ?? '';
            }
            $row[] = $submission->createdAt;
            $row[] = $submission->status;
            $row[] = $submission->pagePath;
            $row[] = $submission->locale;
            $write($stream, $row);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $form->handle . '-submissions.csv"',
            'Cache-Control' => 'no-store',
        ], $csv);
    }

    /**
     * Neutralises CSV formula injection.
     *
     * Every value in this export was typed by a stranger on the public
     * internet, and Excel, LibreOffice and Google Sheets all EXECUTE a cell
     * beginning with = + - @ (or a leading tab/CR) as a formula — which is how
     * a contact form turns into remote command execution on the laptop of
     * whoever opens the leads spreadsheet. Prefixing with an apostrophe keeps
     * the text readable and makes the cell literal.
     */
    private static function csvCell(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }

        return $value;
    }
}
