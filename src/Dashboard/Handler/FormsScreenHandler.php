<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Form\Service\FormService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/forms — define the forms a page can embed with
 * `<div data-fs-form="handle"></div>`, and read what people have sent.
 *
 * ?form={handle} prefills the editor with the stored definition;
 * ?submissions={id} opens that form's submissions.
 */
final class FormsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly FormService $forms,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();
        $forms = $this->forms->list($site->handle);

        $editHandle = trim((string) ($query['form'] ?? ''));
        $editForm = null;
        foreach ($forms as $form) {
            if ($form->handle === $editHandle) {
                $editForm = $form;
                break;
            }
        }

        // Which form's submissions to show. Defaults to the first form so the
        // screen is useful on arrival rather than making you pick.
        $selectedId = (int) ($query['submissions'] ?? 0);
        if ($selectedId === 0 && $forms !== []) {
            $selectedId = $forms[0]->id;
        }
        $selected = null;
        foreach ($forms as $form) {
            if ($form->id === $selectedId) {
                $selected = $form;
                break;
            }
        }

        // The spam folder is a VIEW of the same list, not a second screen:
        // ?folder=spam. Keeping it one screen is what makes a false positive
        // findable — a separate page nobody has a reason to open is the same
        // as deleting the submission, just slower.
        $folder = ($query['folder'] ?? '') === 'spam' ? 'spam' : 'inbox';

        return $this->dash->render($request, 'forms.html.twig', [
            'title' => $site->name . ' forms — fastsite',
            'site' => $site,
            'forms' => $forms,
            'counts' => $this->forms->counts($site->handle),
            'edit_form' => $editForm,
            'selected' => $selected,
            'folder' => $folder,
            'submissions' => $selected !== null
                ? $this->forms->submissions($site->handle, $selected->id, 100, 0, $folder)
                : [],
            // Turnstile renders nothing unless BOTH halves of the key pair are
            // stored, so a form with the box ticked and no keys is silently
            // unprotected. The screen says so rather than letting it look on.
            'turnstile_ready' => $site->turnstileConfigured(),
            'spam_mode' => $site->spamMode,
            'spam_retention_days' => $site->spamRetentionDays,
            // The screen has to say out loud that mail is not set up: a form
            // that collects perfectly and notifies nobody looks completely
            // healthy from every other angle, which is how leads get missed.
            'smtp_ready' => $site->smtpConfigured(),
            'field_types' => \Fastsite\Form\Dto\FormField::TYPES,
            // The message editor shows each key with the built-in wording for
            // THIS site's language as the placeholder, so the box you type
            // into shows what you are replacing rather than a bare key.
            'message_keys' => $this->messageHints($site->primaryLocale),
            'message_locales' => \Fastsite\Form\FormMessages::builtInLocales(),
        ]);
    }

    /**
     * @return array<string, string> key => the built-in wording it replaces
     */
    private function messageHints(string $locale): array
    {
        $pack = \Fastsite\Form\FormMessages::for($locale);
        $hints = [];
        foreach (\Fastsite\Form\FormMessages::KEYS as $key) {
            $hints[$key] = $pack->get($key);
        }

        return $hints;
    }
}
