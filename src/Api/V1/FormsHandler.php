<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Form\Dto\Form;
use Fastsite\Form\Dto\FormField;
use Fastsite\Form\Dto\Submission;
use Fastsite\Form\Service\FormService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Forms REST surface, one handler dispatched by method (same shape as
 * ComponentsHandler):
 *   GET    /api/v1/sites/{site}/forms                        (forms:read)
 *   GET    /api/v1/sites/{site}/forms/{handle}               (forms:read)
 *   PUT    /api/v1/sites/{site}/forms/{handle}               (forms:write)
 *   DELETE /api/v1/sites/{site}/forms/{handle}               (forms:write)
 *   GET    /api/v1/sites/{site}/forms/{handle}/submissions   (forms:read)
 *
 * Every write republishes: the form's markup is generated at build time, so a
 * changed definition does not reach the live pages until they are re-rendered.
 */
final class FormsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly FormService $forms,
        private readonly PublishTrigger $publish,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        /** @var AccessContext|null $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $formHandle = (string) ($request->getAttribute('handle') ?? '');
        $method = strtoupper($request->getMethod());
        $isSubmissions = str_ends_with($request->getUri()->getPath(), '/submissions');

        if ($method === 'GET' && $formHandle === '') {
            $counts = $this->forms->counts($handle);

            return ApiResponse::success([
                'forms' => array_map(
                    static fn (Form $f): array => self::toArray($f) + ['submissions' => $counts[$f->id]['total'] ?? 0],
                    $this->forms->list($handle),
                ),
                'smtp_configured' => $site->smtpConfigured(),
            ]);
        }

        $form = $this->forms->find($handle, $formHandle);

        if ($method === 'GET' && $isSubmissions) {
            if ($form === null) {
                return ApiResponse::error('Unknown form: ' . $formHandle, 404);
            }
            $query = $request->getQueryParams();

            return ApiResponse::success([
                'submissions' => array_map(
                    self::submissionToArray(...),
                    $this->forms->submissions($handle, $form->id, (int) ($query['limit'] ?? 50), (int) ($query['offset'] ?? 0)),
                ),
            ]);
        }

        if ($method === 'GET') {
            return $form === null
                ? ApiResponse::error('Unknown form: ' . $formHandle, 404)
                : ApiResponse::success(['form' => self::toArray($form)]);
        }

        if ($method === 'PUT') {
            $body = $request->getParsedBody();
            $body = \is_array($body) ? $body : [];
            $body['handle'] = $formHandle;

            try {
                $saved = $this->forms->save($handle, $body);
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            $this->audit->log($access, 'form.upsert', $handle, 'form:' . $saved->handle, [], ApiHelpers::clientIp($request));
            $this->publish->trigger($site, 'publish: form "' . $saved->handle . '" updated via API', 'api');

            return ApiResponse::success(['form' => self::toArray($saved)]);
        }

        if ($method === 'DELETE') {
            if ($form === null) {
                return ApiResponse::error('Unknown form: ' . $formHandle, 404);
            }
            $this->forms->delete($handle, $form->id);
            $this->audit->log($access, 'form.delete', $handle, 'form:' . $formHandle, [], ApiHelpers::clientIp($request));
            $this->publish->trigger($site, 'publish: form "' . $formHandle . '" deleted via API', 'api');

            return ApiResponse::success(['deleted' => $formHandle]);
        }

        return ApiResponse::error('Method not allowed', 405);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Form $form): array
    {
        return [
            'handle' => $form->handle,
            'name' => $form->name,
            'recipients' => $form->recipients,
            'subject' => $form->subject,
            'fields' => array_map(static fn (FormField $f): array => $f->toArray(), $form->fields),
            'submit_label' => $form->submitLabel,
            'success_path' => $form->successPath,
            'success_message' => $form->successMessage,
            'consent_text' => $form->consentText,
            'autoresponder_enabled' => $form->autoresponderEnabled,
            'turnstile' => $form->turnstile,
            'messages' => $form->messages,
            'enabled' => $form->enabled,
            'embed' => '<div data-fs-form="' . $form->handle . '"></div>',
            'updated_at' => $form->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function submissionToArray(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'values' => $submission->payload,
            'reply_to' => $submission->replyTo,
            'status' => $submission->status,
            'error' => $submission->error,
            // The score AND the reasons. A number on its own is unarguable,
            // which makes it useless to a caller deciding whether the guard
            // got this one wrong.
            'spam_score' => $submission->spamScore,
            'spam_reasons' => $submission->spamReasons,
            'page' => $submission->pagePath,
            'locale' => $submission->locale,
            'created_at' => $submission->createdAt,
            'sent_at' => $submission->sentAt,
        ];
    }
}
