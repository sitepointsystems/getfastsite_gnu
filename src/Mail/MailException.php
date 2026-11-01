<?php

declare(strict_types=1);

namespace Fastsite\Mail;

/**
 * A send that did not happen, and WHY — with the phase it died in.
 *
 * "It just doesn't send" is unattributable, and we have already paid for that
 * lesson once on FTP: the fix there was reading the phase off the actual
 * conversation so the four completely different causes (never reached the
 * server / TLS refused / login rejected / accepted then failed) stop looking
 * identical. Same reasoning, same shape.
 *
 * `transport` marks a failure with no SMTP response at all — connect refused,
 * DNS gone, a wire that went silent. Those are worth retrying on a schedule;
 * a 550 from a server that answered clearly is not.
 */
final class MailException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $phase = '',
        public readonly string $conversation = '',
        public readonly bool $transport = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Plain language for the phase, for the settings screen and `mail:test`.
     * Deliberately says ONE thing: a message that offers "the host stopped
     * responding" and "it never reached the server" in one breath tells the
     * reader nothing.
     */
    public function hint(): string
    {
        return match ($this->phase) {
            'config' => 'Nothing is set up to send with yet. Add an SMTP host and a sender address on the site\'s settings, then retry — the submission itself is safe.',
            'connect' => 'Never reached the mail server. Check the host and port, and whether outbound SMTP is open — many networks block port 25 outright.',
            'greeting' => 'Connected, but the server never sent its greeting. That is usually the wrong encryption for the port: implicit TLS on 465, STARTTLS on 587.',
            'starttls' => 'The server accepted the connection but the TLS handshake failed. If the certificate is self-signed, turn off certificate verification for this site.',
            'auth' => 'The server rejected the username or password. Microsoft 365 and Google both need an app password or explicitly enabled SMTP AUTH — a normal account password often will not work.',
            'envelope' => 'The server refused the sender or a recipient address. Most providers require the From address to be one this account is allowed to send as.',
            'data' => 'The server accepted the envelope but rejected the message itself.',
            default => 'The mail server refused the request.',
        };
    }
}
