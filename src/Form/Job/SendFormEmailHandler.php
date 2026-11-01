<?php

declare(strict_types=1);

namespace Fastsite\Form\Job;

use Fastsite\Form\Service\FormMailer;
use Fastsite\Form\Service\FormService;
use Fastsite\Mail\MailException;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Sends the notification for one stored submission, then the optional
 * autoresponder.
 *
 * Deliberately NOT under SiteLock: this writes one submission row and touches
 * no site files, and taking the lock would queue every lead behind whatever
 * build happens to be running.
 *
 * Idempotent on the submission's own status — a retry after a partial failure
 * re-reads the row and stops if the notification already went out, so nobody
 * gets the same enquiry twice.
 */
final class SendFormEmailHandler implements JobHandler
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly FormService $forms,
        private readonly FormMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'send_form_email';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('send_form_email needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $submissionId = (int) ($job->payload['submission_id'] ?? 0);
        $submission = $submissionId > 0 ? $this->forms->findSubmission($handle, $submissionId) : null;
        if ($submission === null) {
            // Pruned, or the form was deleted underneath us. Nothing to retry.
            $this->logger->info('Form submission gone before send', ['site' => $handle, 'submission' => $submissionId]);

            return;
        }
        if ($submission->status === 'sent') {
            return;
        }

        $form = $this->forms->findById($handle, $submission->formId);
        if ($form === null) {
            $this->forms->markFailed($handle, $submission->id, 'The form definition was deleted before this could be sent.');

            return;
        }

        $context->progress('Sending ' . $form->label() . ' notification…');

        try {
            $this->mailer->notify($site, $form, $submission);
        } catch (MailException $e) {
            // The reason has to survive on the row: the dashboard's Submissions
            // list is the only place anyone will ever look for "why did this
            // not arrive", and the app log lives inside the container.
            $this->forms->markFailed(
                $handle,
                $submission->id,
                $e->getMessage() . ' — ' . $e->hint(),
                $e->conversation,
            );
            $this->logger->error('Form notification failed', [
                'site' => $handle,
                'form' => $form->handle,
                'submission' => $submission->id,
                'phase' => $e->phase,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                sprintf('Form "%s" notification failed at %s: %s', $form->handle, $e->phase, $e->getMessage()),
                0,
                $e,
            );
        }

        $this->forms->markSent($handle, $submission->id);
        $this->logger->info('Form notification sent', [
            'site' => $handle,
            'form' => $form->handle,
            'submission' => $submission->id,
            'recipients' => \count($form->recipients),
        ]);

        // The autoresponder is a courtesy to the visitor and must never cost
        // the owner their notification: the lead is already delivered and the
        // row is already marked sent, so a failure here is logged and dropped
        // rather than retried (a retry would re-send the notification too).
        $context->heartbeat();
        try {
            $this->mailer->autorespond($site, $form, $submission);
        } catch (\Throwable $e) {
            $this->logger->warning('Form autoresponder failed', [
                'site' => $handle,
                'form' => $form->handle,
                'submission' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
