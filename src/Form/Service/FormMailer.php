<?php

declare(strict_types=1);

namespace Fastsite\Form\Service;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\Dto\Submission;
use Fastsite\Mail\MailException;
use Fastsite\Mail\MailTransport;
use Fastsite\Mail\Message;
use Fastsite\Mail\SmtpConfig;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Site\Dto\Site;

/**
 * Turns a stored submission into the mail that tells someone about it.
 *
 * THE ADDRESS RULE, which is the difference between a form that works and one
 * whose mail silently lands in spam: From is ALWAYS the site's configured
 * sender, never the visitor. Providers reject, or SPF-fail, a From they did
 * not authenticate — and a From the visitor controls is a forgery vector on
 * the customer's own domain. The visitor goes in Reply-To, so hitting reply in
 * the mail client still reaches them, which is the only thing the owner
 * actually wanted from it.
 */
final class FormMailer
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly TokenCipher $cipher,
    ) {
    }

    public function configFor(Site $site): SmtpConfig
    {
        return SmtpConfig::fromSite($site, $this->cipher);
    }

    /**
     * Connect and authenticate without sending anything.
     *
     * @return string the masked SMTP conversation
     *
     * @throws MailException
     */
    public function probe(Site $site): string
    {
        $config = $this->configFor($site);
        if (!$config->isConfigured()) {
            throw new MailException('Set an SMTP host and a sender address first.', 'config');
        }

        return $this->transport->probe($config);
    }

    /**
     * A real message to a real inbox. The connection probe proves the
     * credentials; only this proves DELIVERY, which is the part that actually
     * fails — a provider can accept the login and still silently drop or
     * junk mail from an address the account is not allowed to send as.
     *
     * @throws MailException
     */
    public function sendTest(Site $site, string $to): void
    {
        $config = $this->configFor($site);
        if (!$config->isConfigured()) {
            throw new MailException('Set an SMTP host and a sender address first.', 'config');
        }
        $recipient = Message::addr($to);
        if ($recipient === '') {
            throw new MailException('That is not a valid email address to send the test to.', 'envelope');
        }

        $this->transport->send($config, Message::create(
            fromEmail: $config->fromEmail,
            fromName: $config->fromName,
            to: [$recipient],
            subject: 'Test email from ' . ($site->name !== '' ? $site->name : $site->handle),
            textBody: "This is a test from your site's form settings.\n\n"
                . "If you are reading this, form notifications will reach this inbox.\n\n"
                . 'Sent through ' . $config->host . ':' . $config->port
                . ' as ' . $config->fromEmail . ".\n",
        ));
    }

    /**
     * Sends the owner notification. Throws MailException on failure so the
     * caller can record the phase and retry.
     */
    public function notify(Site $site, Form $form, Submission $submission): void
    {
        $config = $this->configFor($site);
        if (!$config->isConfigured()) {
            throw new MailException(
                'This site has no SMTP host and sender configured, so nothing can be sent. '
                . 'The submission itself is saved and will send once mail is set up.',
                'config',
            );
        }
        if ($form->recipients === []) {
            throw new MailException('This form has no recipient address.', 'envelope');
        }

        $values = $this->labelled($form, $submission);
        $replyName = $this->replyName($form, $submission);

        $this->transport->send($config, Message::create(
            fromEmail: $config->fromEmail,
            fromName: $config->fromName,
            to: $form->recipients,
            subject: $this->subject($site, $form, $submission),
            textBody: $this->textBody($site, $form, $submission, $values),
            htmlBody: $this->htmlBody($site, $form, $submission, $values),
            replyTo: $submission->replyTo,
            replyToName: $replyName,
        ));
    }

    /**
     * The optional "thanks, we got it" back to the visitor.
     *
     * Off by default, and worth understanding why: this sends mail to an
     * address a stranger typed, from the customer's domain. Someone can put a
     * third party's address in the form and make the customer's server mail
     * them — classic backscatter, and it is the customer's sending reputation
     * that pays. Enabled only deliberately, per form, and never sent to an
     * address that failed validation.
     */
    public function autorespond(Site $site, Form $form, Submission $submission): void
    {
        if (!$form->autoresponderEnabled || $submission->replyTo === '') {
            return;
        }
        $config = $this->configFor($site);
        if (!$config->isConfigured()) {
            return;
        }

        $values = $this->labelled($form, $submission);
        $subject = $this->fill(
            $form->autoresponderSubject !== '' ? $form->autoresponderSubject : 'Thanks for getting in touch',
            $site,
            $submission,
        );
        $body = $this->fill($form->autoresponderBody, $site, $submission);
        if (trim($body) === '') {
            $body = "Thanks for getting in touch. We have received your message and will reply as soon as we can.\n\n"
                . $site->name;
        }

        $this->transport->send($config, Message::create(
            fromEmail: $config->fromEmail,
            fromName: $config->fromName,
            to: [$submission->replyTo],
            subject: $subject,
            textBody: $body . "\n\n" . str_repeat('-', 40) . "\n"
                . "Your message:\n\n" . $this->plainList($values),
        ));
    }

    /**
     * Field label => submitted value, in the order the form defines them, so
     * the notification reads like the form rather than like a database row.
     *
     * @return array<string, string>
     */
    private function labelled(Form $form, Submission $submission): array
    {
        $out = [];
        foreach ($form->fields as $field) {
            $out[$field->label] = $submission->payload[$field->name] ?? '';
        }

        return $out;
    }

    private function replyName(Form $form, Submission $submission): string
    {
        $field = $form->nameField();

        return $field !== null ? ($submission->payload[$field->name] ?? '') : '';
    }

    private function subject(Site $site, Form $form, Submission $submission): string
    {
        $template = $form->subject !== ''
            ? $form->subject
            : 'New ' . $form->label() . ' submission';

        return $this->fill($template, $site, $submission);
    }

    /**
     * `{field_name}` placeholders, plus `{site}`. Values are inserted raw here
     * and escaped by whichever body builder consumes them — the subject goes
     * through Message::header, which strips anything that could forge a header.
     */
    private function fill(string $template, Site $site, Submission $submission): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }
        $replacements = ['{site}' => $site->name];
        foreach ($submission->payload as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<string, string> $values
     */
    private function textBody(Site $site, Form $form, Submission $submission, array $values): string
    {
        $lines = [
            $form->label() . ' — ' . $site->name,
            '',
            $this->plainList($values),
            str_repeat('-', 40),
            'Submitted: ' . $submission->createdAt,
        ];
        if ($submission->pagePath !== '') {
            $lines[] = 'Page: ' . rtrim($site->canonicalBaseUrl(), '/') . '/' . ltrim($submission->pagePath, '/');
        }
        if ($submission->locale !== '') {
            $lines[] = 'Language: ' . $submission->locale;
        }
        if ($submission->replyTo !== '') {
            $lines[] = 'Reply directly to this email to answer ' . $submission->replyTo . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $values
     */
    private function plainList(array $values): string
    {
        $out = '';
        foreach ($values as $label => $value) {
            $out .= $label . ":\n" . ($value !== '' ? $value : '(blank)') . "\n\n";
        }

        return $out;
    }

    /**
     * @param array<string, string> $values
     */
    private function htmlBody(Site $site, Form $form, Submission $submission, array $values): string
    {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($values as $label => $value) {
            $rows .= '<tr>'
                . '<th align="left" valign="top" style="padding:6px 12px 6px 0;font:600 14px system-ui,sans-serif;white-space:nowrap">'
                . $esc($label) . '</th>'
                . '<td style="padding:6px 0;font:14px system-ui,sans-serif">'
                . ($value !== '' ? nl2br($esc($value)) : '<em style="opacity:.6">(blank)</em>')
                . '</td></tr>';
        }

        $meta = 'Submitted ' . $esc($submission->createdAt);
        if ($submission->pagePath !== '') {
            $url = rtrim($site->canonicalBaseUrl(), '/') . '/' . ltrim($submission->pagePath, '/');
            $meta .= ' from <a href="' . $esc($url) . '">' . $esc($url) . '</a>';
        }

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f9">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:24px">'
            . '<h1 style="margin:0 0 4px;font:600 18px system-ui,sans-serif">' . $esc($form->label()) . '</h1>'
            . '<p style="margin:0 0 20px;font:14px system-ui,sans-serif;opacity:.7">' . $esc($site->name) . '</p>'
            . '<table cellpadding="0" cellspacing="0" style="width:100%">' . $rows . '</table>'
            . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">'
            . '<p style="margin:0;font:13px system-ui,sans-serif;opacity:.6">' . $meta . '</p>'
            . '</div></body></html>';
    }
}
