<?php

declare(strict_types=1);

namespace Fastsite\Form\Handler;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\FormMessages;
use Fastsite\Form\Service\FormRenderer;
use Fastsite\Form\Service\FormService;
use Fastsite\Form\Service\SubmissionThrottle;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /f/{site}/{form} — the one dynamic endpoint a static site has.
 *
 * A native HTML form on the published page posts here cross-origin, which
 * needs no CORS (a normal form submission is not a preflighted request) and no
 * CSP change (the build sets no form-action directive, and it has no
 * default-src fallback). So the whole feature costs the published page exactly
 * zero bytes of JavaScript.
 *
 * The submission is STORED before any mail is attempted, and the send happens
 * on the queue. Two reasons, both learned elsewhere in this codebase: a slow
 * or dead SMTP server must not hold a visitor's browser open, and a lead must
 * survive a mail configuration that is simply wrong.
 */
final class FormSubmitHandler implements RequestHandlerInterface
{
    /**
     * The challenge token Turnstile's widget posts. Named by Cloudflare, not
     * by us, so it carries no `_fs_` prefix.
     */
    public const TURNSTILE_TOKEN = 'cf-turnstile-response';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly FormService $forms,
        private readonly SubmissionThrottle $throttle,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
        private readonly \Fastsite\Form\Spam\SpamScorer $scorer,
        private readonly \Fastsite\Form\Spam\CaptchaVerifier $captcha,
        private readonly ?\Fastsite\Shared\Crypto\TokenCipher $cipher = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $siteHandle = (string) $request->getAttribute('site');
        $formHandle = (string) $request->getAttribute('form');

        $site = $this->sites->findByHandle($siteHandle);
        if ($site === null || $site->deletedAt !== null) {
            $generic = FormMessages::for('en');

            return $this->page(404, $generic->get('notfound_title'), $generic->get('notfound_body'));
        }

        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        // The language to answer in: what the page said it was rendered in,
        // else the site's primary locale. A hand-authored form sends no
        // _fs_locale, and on a single-language site the fallback is the right
        // answer anyway.
        $locale = trim((string) ($body[FormRenderer::LOCALE] ?? ''));
        if ($locale === '') {
            $locale = $site->primaryLocale;
        }

        $form = $this->forms->find($siteHandle, $formHandle);
        $msg = FormMessages::for($locale, $form?->messages ?? []);

        if ($form === null || !$form->enabled) {
            return $this->page(404, $msg->get('notfound_title'), $msg->get('notfound_body'), '', $msg, $locale);
        }

        if ($this->throttle->exceeded($siteHandle, $formHandle, $ip)) {
            return $this->page(
                429,
                $msg->get('throttled_title'),
                $msg->get('throttled_body'),
                $this->backLink($site, $body),
                $msg,
                $locale,
            );
        }

        $checked = $this->forms->validate($form, $body, $locale);

        // A honeypot hit is answered exactly like a success. Telling a bot it
        // was caught only teaches it which field to leave alone next time.
        // It is deliberately NOT stored: a spam flood would otherwise evict
        // real submissions from the per-form retention window.
        if ($checked['spam']) {
            $this->logger->info('Form honeypot triggered', ['site' => $siteHandle, 'form' => $formHandle]);

            return $this->success($site, $form, $body, $msg, $locale);
        }

        if ($checked['errors'] !== []) {
            return $this->page(
                400,
                $msg->get('error_title'),
                implode(' ', $checked['errors']),
                $this->backLink($site, $body),
                $msg,
                $locale,
            );
        }

        // The challenge, when this form opted in. Unlike the honeypot this
        // gets a REAL error page rather than a fake success: a human whose
        // widget failed to load, or who never completed it, has to be told so
        // they can try again. Answering them with "sent" would silently bin a
        // genuine enquiry, which is the exact failure this whole feature
        // exists to stop. A bot learns nothing from the message — the widget
        // is already visible in the page's HTML.
        if ($form->turnstile && $site->turnstileConfigured()) {
            $result = $this->captcha->verify(
                $this->turnstileSecret($site),
                (string) ($body[self::TURNSTILE_TOKEN] ?? ''),
                $ip,
            );

            if (!$result->conclusive) {
                // We never got an answer out of Cloudflare. The visitor is not
                // at fault and must not pay for it, so the submission proceeds
                // on the content score alone — one guard down, not zero.
                $this->logger->warning('Turnstile verification inconclusive — allowing the submission', [
                    'site' => $siteHandle,
                    'form' => $formHandle,
                    'error' => $result->error,
                ]);
            } elseif (!$result->passed) {
                $this->logger->info('Turnstile rejected a submission', [
                    'site' => $siteHandle,
                    'form' => $formHandle,
                    'error' => $result->error,
                ]);

                return $this->page(
                    400,
                    $msg->get('challenge_title'),
                    $msg->get('challenge'),
                    $this->backLink($site, $body),
                    $msg,
                    $locale,
                );
            }
        }

        // Content scoring. This runs on EVERY submission regardless of
        // Turnstile, because it is the only guard that covers the paths the
        // widget cannot: a no-JavaScript submit, and a bespoke form that posts
        // straight to this endpoint with markup the engine never generated.
        $verdict = $site->spamMode === 'off'
            ? \Fastsite\Form\Spam\SpamVerdict::clean()
            : $this->scorer->score($form, $checked['values'], $locale);

        // `log` records the verdict and delivers anyway — the mode a site runs
        // for a week to see what WOULD have been held before anything is.
        $hold = $verdict->isSpam && $site->spamMode === 'on';

        $submission = $this->forms->record(
            $siteHandle,
            $form,
            $checked['values'],
            [
                'locale' => (string) ($body[FormRenderer::LOCALE] ?? ''),
                'page' => (string) ($body[FormRenderer::PAGE] ?? ''),
                'ip' => $ip,
                'ua' => $request->getHeaderLine('User-Agent'),
            ],
            $verdict,
            $hold,
        );

        if ($hold) {
            // Stored, never notified, and answered exactly like a success.
            // The visitor gets the normal confirmation because they might be a
            // real person the scorer got wrong, and the owner finds them in
            // the spam folder rather than nowhere at all.
            $this->logger->info('Form submission held as spam', [
                'site' => $siteHandle,
                'form' => $formHandle,
                'submission' => $submission->id,
                'score' => $verdict->score,
                'reasons' => $verdict->reasons,
            ]);

            return $this->success($site, $form, $body, $msg, $locale);
        }

        // Queued, never inline: a mail server that takes 30 seconds to answer
        // must not be something the visitor sits and waits for.
        try {
            $this->queue->enqueue(
                type: 'send_form_email',
                payload: ['submission_id' => $submission->id, 'form_id' => $form->id],
                siteHandle: $siteHandle,
            );
        } catch (\Throwable $e) {
            // The row is already on disk, so the lead is safe; the scheduler's
            // retry sweep will pick it up. Never fail the visitor for this.
            $this->logger->error('Could not queue form mail', [
                'site' => $siteHandle,
                'form' => $formHandle,
                'submission' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success($site, $form, $body, $msg, $locale);
    }

    /**
     * The decrypted Turnstile secret, or '' when it cannot be read.
     *
     * An unreadable secret deliberately produces an INCONCLUSIVE verification
     * rather than a rejection (TurnstileVerifier returns `unreachable` for an
     * empty secret): a key the engine cannot decrypt is our problem, and
     * taking the customer's form offline over it would be a self-inflicted
     * outage with no message explaining itself.
     */
    private function turnstileSecret(Site $site): string
    {
        $enc = $site->turnstileSecretEnc;
        if ($enc === null || $enc === '' || $this->cipher === null) {
            return '';
        }

        try {
            return $this->cipher->decrypt($enc) ?? '';
        } catch (\Throwable $e) {
            $this->logger->error('Could not decrypt the Turnstile secret', [
                'site' => $site->handle,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * 303 to the site's own thank-you page when it has one, otherwise an
     * engine-rendered confirmation. 303 (not 302) is what turns the POST into
     * a GET, so a refresh on the thank-you page cannot resubmit the form.
     *
     * @param array<string, mixed> $body
     */
    private function success(Site $site, Form $form, array $body, FormMessages $msg, string $locale): ResponseInterface
    {
        $target = '';
        $base = rtrim($site->canonicalBaseUrl(), '/');

        if ($form->successPath !== '' && $base !== '') {
            $target = $base . '/' . ltrim($form->successPath, '/');
        }

        if ($target !== '') {
            return new Response(303, ['Location' => $target, 'Cache-Control' => 'no-store']);
        }

        $message = $form->successMessage !== '' ? $form->successMessage : $msg->get('sent_body');

        return $this->page(200, $msg->get('sent_title'), $message, $this->backLink($site, $body), $msg, $locale);
    }

    /**
     * The page to send the visitor back to. The posted value is only honoured
     * when it is on the site's OWN canonical origin — otherwise this endpoint
     * would be an open redirect wearing the customer's domain, which is the
     * single most valuable thing a phisher could get out of a contact form.
     *
     * @param array<string, mixed> $body
     */
    private function backLink(Site $site, array $body): string
    {
        $base = rtrim($site->canonicalBaseUrl(), '/');
        if ($base === '') {
            return '';
        }

        $posted = (string) ($body[FormRenderer::RETURN_TO] ?? '');
        if ($posted !== '' && str_starts_with($posted, $base . '/')) {
            return $posted;
        }

        return $base . '/';
    }

    /**
     * A minimal, self-contained response page. Inline CSS only, no external
     * resources, no script — it is served from the engine's origin and has no
     * access to the site's shell, so it stays deliberately plain rather than
     * pretending to be part of the site.
     */
    private function page(
        int $status,
        string $title,
        string $message,
        string $back = '',
        ?FormMessages $msg = null,
        string $lang = 'en',
    ): ResponseInterface {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $backLabel = ($msg ?? FormMessages::for('en'))->get('back');
        $lang = preg_match('/^[a-z]{2}(-[A-Za-z0-9]{2,8})?$/i', $lang) === 1 ? $lang : 'en';
        $link = $back !== ''
            ? '<p><a href="' . $esc($back) . '">' . $esc($backLabel) . '</a></p>'
            : '';

        $html = '<!doctype html><html lang="' . $esc($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $esc($title) . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7f9;color:#111}'
            . 'main{max-width:34rem;padding:2.5rem;background:#fff;border-radius:.75rem;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.08);margin:1rem}'
            . 'h1{margin:0 0 .5rem;font-size:1.35rem}p{margin:.75rem 0 0}'
            . 'a{color:#2a86ff}</style></head><body><main>'
            . '<h1>' . $esc($title) . '</h1><p>' . $esc($message) . '</p>' . $link
            . '</main></body></html>';

        return new Response(
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $html,
        );
    }
}
