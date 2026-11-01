<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Form\Service\FormService;
use Fastsite\Form\Spam\SpamScorer;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\Queue\JobQueue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/forms/submissions/{op} — the submission recovery
 * operations: `retry`, `release`, `rescore`, `purge-spam`.
 *
 * These existed ONLY inside the dashboard action handler, which meant an agent
 * could configure and re-target a site's entire deploy target over the API but
 * could not recover the leads when the mail config had been wrong — the exact
 * situation that prompted the spam guard. A recovery path reachable from one
 * surface is a recovery path most callers do not have.
 */
final class FormSubmissionsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly FormService $forms,
        private readonly JobQueue $queue,
        private readonly SpamScorer $scorer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $op = (string) $request->getAttribute('op');

        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];

        // Optional narrowing to one form; an unknown handle is a 404 rather
        // than a silent site-wide operation the caller did not ask for.
        $formId = null;
        $formHandle = trim((string) ($body['form'] ?? ''));
        if ($formHandle !== '') {
            $form = $this->forms->find($handle, $formHandle);
            if ($form === null) {
                return ApiResponse::error('Unknown form: ' . $formHandle, 404);
            }
            $formId = $form->id;
        }

        switch ($op) {
            case 'retry':
                $ids = $this->forms->retryFailed($handle, $formId);
                $this->enqueueAll($handle, $ids);

                return ApiResponse::success(['requeued' => \count($ids), 'submission_ids' => $ids]);

            case 'release':
                $one = (int) ($body['submission_id'] ?? 0);
                $ids = $this->forms->releaseSpam($handle, $one > 0 ? $one : null, $formId);
                $this->enqueueAll($handle, $ids);

                return ApiResponse::success(['released' => \count($ids), 'submission_ids' => $ids]);

            case 'rescore':
                $apply = (bool) ($body['apply'] ?? false);
                $rows = $this->forms->rescore(
                    $handle,
                    fn (\Fastsite\Form\Dto\Form $f, array $v, string $l): \Fastsite\Form\Spam\SpamVerdict
                        => $this->scorer->score($f, $v, $l),
                    $formId,
                    $apply,
                );

                return ApiResponse::success([
                    'applied' => $apply,
                    'scanned' => \count($rows),
                    'would_hold' => \count(array_filter($rows, static fn (array $r): bool => $r['would_hold'])),
                    'threshold' => SpamScorer::THRESHOLD,
                    'rows' => $rows,
                ]);

            case 'mark-spam':
                // The judgement the scorer could not make. `status` narrows it
                // to a set someone can actually have reviewed ("everything
                // that failed to send"), which is what makes a bulk call here
                // defensible at all.
                $one = (int) ($body['submission_id'] ?? 0);
                $status = trim((string) ($body['status'] ?? ''));
                $ids = $this->forms->markSpamWhere(
                    $handle,
                    $one > 0 ? $one : null,
                    $formId,
                    $status !== '' ? $status : null,
                );

                return ApiResponse::success(['marked' => \count($ids), 'submission_ids' => $ids]);

            case 'purge-spam':
                // `days` is explicit here rather than read from the site row:
                // an API caller deleting data should have to say how much.
                $days = \array_key_exists('days', $body) ? (int) $body['days'] : 0;

                return ApiResponse::success(['deleted' => $this->forms->purgeSpam($handle, $days)]);

            default:
                return ApiResponse::error(
                    'Unknown operation: ' . $op . ' (retry, release, rescore, mark-spam, purge-spam)',
                    404,
                );
        }
    }

    /** @param list<int> $ids */
    private function enqueueAll(string $handle, array $ids): void
    {
        foreach ($ids as $id) {
            $this->queue->enqueue(
                type: 'send_form_email',
                siteHandle: $handle,
                payload: ['submission_id' => $id],
            );
        }
    }
}
