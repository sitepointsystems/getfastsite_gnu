<?php

declare(strict_types=1);

namespace Fastsite\Mail;

/**
 * The seam between "build a message" and "open a socket" — the same shape as
 * CfTransport and FtpTransport, and for the same reason: every test above this
 * line runs without a mail server.
 */
interface MailTransport
{
    /**
     * @throws MailException on any failure, carrying the phase it died in
     */
    public function send(SmtpConfig $config, Message $message): void;

    /**
     * Connect and authenticate WITHOUT sending anything, returning the masked
     * conversation. This is the "Send test email" button's other half: it
     * proves the credentials before a real lead depends on them.
     *
     * @return string the masked SMTP conversation
     *
     * @throws MailException
     */
    public function probe(SmtpConfig $config): string;
}
