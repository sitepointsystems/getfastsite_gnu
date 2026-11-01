<?php

declare(strict_types=1);

namespace Fastsite\Mail;

use Fastsite\Shared\Net\HostVetter;

/**
 * A hand-rolled SMTP client over stream sockets — no new composer dependency,
 * the same call the codebase already makes for SigV4 (S3Client), FTP
 * (CurlFtpTransport), TOTP and FIGlet. SMTP is a line protocol; the part that
 * actually bites is MIME and header encoding, and that is ours to get right
 * with or without a library.
 *
 * Three decisions worth not re-litigating later:
 *
 * 1. THE BODY IS ALWAYS BASE64. It costs a third more bytes and buys immunity
 *    from the three classic SMTP corruptions in one move: the 998-octet line
 *    limit, dot-stuffing (a body line beginning with "." ends the message
 *    early), and 8-bit content on a server that never advertised 8BITMIME.
 *    Form submissions carry arbitrary visitor text; none of those are
 *    hypothetical.
 *
 * 2. WE CONNECT TO A VETTED IP BUT VERIFY AGAINST THE HOSTNAME. HostVetter
 *    resolves and rejects private/loopback/reserved addresses, and we dial the
 *    address it returned so a second DNS lookup cannot swap it — a self-serve
 *    editor must not be able to aim a connection at 169.254.169.254.
 *    `peer_name` keeps certificate validation working against the real
 *    hostname anyway. Every vetted address is tried in turn, IPv4 first,
 *    because a host with an unroutable AAAA record otherwise hangs until the
 *    connect timeout while a desktop client on the same credentials connects
 *    instantly — the exact failure the FTP publisher already paid for.
 *
 * 3. EVERY FAILURE CARRIES ITS PHASE. See MailException::hint().
 */
final class SmtpTransport implements MailTransport
{
    private const CONNECT_TIMEOUT = 15;
    private const READ_TIMEOUT = 30;
    private const TRACE_LIMIT = 8000;
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    /** @var list<string> */
    private array $trace = [];

    public function __construct(private readonly HostVetter $vetter)
    {
    }

    public function send(SmtpConfig $config, Message $message): void
    {
        if ($message->recipients() === []) {
            throw new MailException('No valid recipient address.', 'envelope');
        }

        $this->open($config);
        try {
            $this->envelope($config, $message);
            $this->transmit($this->compose($config, $message));
            $this->command('QUIT', [221], 'quit', false);
        } finally {
            $this->close();
        }
    }

    public function probe(SmtpConfig $config): string
    {
        $this->open($config);
        try {
            // MAIL FROM then RSET: proves the server will accept this sender
            // without sending anything. "Authentication worked but you may not
            // send as that address" is a distinct and very common failure, and
            // finding it at setup beats finding it on the first real lead.
            if ($config->fromEmail !== '') {
                $this->command('MAIL FROM:<' . $config->fromEmail . '>', [250], 'envelope');
                $this->command('RSET', [250], 'envelope', false);
            }
            $this->command('QUIT', [221], 'quit', false);

            return $this->conversation();
        } finally {
            $this->close();
        }
    }

    /** Connect, greet, EHLO, upgrade, authenticate. */
    private function open(SmtpConfig $config): void
    {
        $this->trace = [];
        if ($config->host === '') {
            throw new MailException('No SMTP host configured for this site.', 'config', '', false);
        }

        $addresses = $this->resolve($config->host);
        $implicitTls = $config->encryption === 'tls';
        $lastError = '';

        foreach ($addresses as $ip) {
            $socket = @stream_socket_client(
                ($implicitTls ? 'ssl://' : 'tcp://') . $this->authority($ip, $config->port),
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $this->context($config),
            );
            if ($socket !== false) {
                $this->socket = $socket;
                $this->note('* connected to ' . $config->host . ' (' . $ip . ':' . $config->port . ')');
                break;
            }
            $lastError = trim((string) $errstr) !== '' ? trim((string) $errstr) : 'error ' . (int) $errno;
            $this->note('* ' . $ip . ':' . $config->port . ' — ' . $lastError);
        }

        if ($this->socket === null) {
            throw new MailException(
                'Could not connect to ' . $config->host . ':' . $config->port . ' — ' . $lastError,
                'connect',
                $this->conversation(),
                true,
            );
        }

        stream_set_timeout($this->socket, self::READ_TIMEOUT);
        $this->read([220], 'greeting');

        $capabilities = $this->ehlo($config);

        if ($config->encryption === 'starttls') {
            $this->command('STARTTLS', [220], 'starttls');
            $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                $error = error_get_last()['message'] ?? 'handshake failed';
                throw new MailException(
                    'STARTTLS failed: ' . $this->flatten($error),
                    'starttls',
                    $this->conversation(),
                );
            }
            $this->note('* TLS established');
            // RFC 3207: the capability list from before the upgrade is void —
            // AUTH is commonly advertised only after TLS, so skipping this
            // second EHLO makes authentication look unsupported.
            $capabilities = $this->ehlo($config);
        }

        if ($config->username !== '') {
            $this->authenticate($config, $capabilities);
        }
    }

    /** @return string the raw EHLO capability block, uppercased */
    private function ehlo(SmtpConfig $config): string
    {
        $response = $this->command('EHLO ' . $this->heloName($config), [250], 'ehlo', true, false);

        return strtoupper($response);
    }

    private function heloName(SmtpConfig $config): string
    {
        $domain = str_contains($config->fromEmail, '@')
            ? substr((string) strrchr($config->fromEmail, '@'), 1)
            : '';

        return $domain !== '' ? $domain : 'localhost';
    }

    private function authenticate(SmtpConfig $config, string $capabilities): void
    {
        // PLAIN first: one round trip, and it is what every modern provider
        // advertises. LOGIN is the fallback for older servers.
        if (str_contains($capabilities, 'AUTH') && str_contains($capabilities, 'PLAIN')) {
            $token = base64_encode("\0" . $config->username . "\0" . $config->password);
            $this->command('AUTH PLAIN ' . $token, [235], 'auth', true, true, 'AUTH PLAIN *****');

            return;
        }

        $this->command('AUTH LOGIN', [334], 'auth');
        $this->command(base64_encode($config->username), [334], 'auth', true, true, '*****');
        $this->command(base64_encode($config->password), [235], 'auth', true, true, '*****');
    }

    private function envelope(SmtpConfig $config, Message $message): void
    {
        $this->command('MAIL FROM:<' . $config->fromEmail . '>', [250], 'envelope');

        $accepted = 0;
        $rejections = [];
        foreach ($message->recipients() as $recipient) {
            try {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251], 'envelope');
                ++$accepted;
            } catch (MailException $e) {
                // One bad address out of several must not discard the lead for
                // everyone else — collect and carry on.
                $rejections[] = $recipient . ' (' . $e->getMessage() . ')';
            }
        }

        if ($accepted === 0) {
            throw new MailException(
                'Every recipient was rejected: ' . implode('; ', $rejections),
                'envelope',
                $this->conversation(),
            );
        }
        if ($rejections !== []) {
            $this->note('* rejected recipients: ' . implode('; ', $rejections));
        }
    }

    private function transmit(string $body): void
    {
        $this->command('DATA', [354], 'data');
        $this->write($body . self::CRLF . '.' . self::CRLF);
        $this->read([250], 'data');
        $this->note('* message accepted');
    }

    /**
     * The full RFC 5322 message. Base64 body (see the class note); headers
     * RFC 2047 encoded only when they actually need it, so an ASCII subject
     * stays readable in a raw mailbox.
     */
    private function compose(SmtpConfig $config, Message $message): string
    {
        $headers = [
            'Date' => date('r'),
            'Message-ID' => $this->messageId($config),
            'From' => $this->mailbox($config->fromEmail, $config->fromName),
            'To' => implode(', ', $message->to),
            'Subject' => $this->encodeHeader($message->subject),
            'MIME-Version' => '1.0',
        ];
        if ($message->cc !== []) {
            $headers['Cc'] = implode(', ', $message->cc);
        }
        if ($message->replyTo !== '') {
            $headers['Reply-To'] = $this->mailbox($message->replyTo, $message->replyToName);
        }
        // Notifications are transactional and generated: this is what stops a
        // recipient's vacation auto-reply bouncing back into the same mailbox.
        $headers['Auto-Submitted'] = 'auto-generated';

        if ($message->htmlBody !== '') {
            $boundary = 'fs-' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . self::CRLF
                . 'Content-Type: text/plain; charset=UTF-8' . self::CRLF
                . 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF
                . $this->base64($message->textBody) . self::CRLF
                . '--' . $boundary . self::CRLF
                . 'Content-Type: text/html; charset=UTF-8' . self::CRLF
                . 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF
                . $this->base64($message->htmlBody) . self::CRLF
                . '--' . $boundary . '--';
        } else {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';
            $body = $this->base64($message->textBody);
        }

        $out = '';
        foreach ($headers as $name => $value) {
            // Belt and braces over Message's own strip: nothing reaches the
            // wire with a bare newline in a header, whatever built it.
            $out .= $name . ': ' . $this->flatten((string) $value) . self::CRLF;
        }

        return $out . self::CRLF . $body;
    }

    private function messageId(SmtpConfig $config): string
    {
        $domain = str_contains($config->fromEmail, '@')
            ? substr((string) strrchr($config->fromEmail, '@'), 1)
            : 'fastsite.local';

        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }

    /**
     * `Display Name <addr@example.com>`, with the display name made safe.
     *
     * Stripping CR/LF already stops a visitor's name from forging a new header
     * — but it leaves the rest of their text sitting in the display name, and
     * RFC 5322 "specials" (a colon, angle brackets, a comma, a quote) are not
     * legal there unquoted. A lenient parser handed
     * `Reply-To: Ada X-Evil: yes <ada@example.com>` is being asked to guess,
     * and guessing is not something to leave to a mail server we do not
     * control. So: non-ASCII becomes an encoded-word (which must NOT be
     * quoted), anything containing a special becomes a quoted-string with the
     * backslashes and quotes escaped, and plain names stay plain.
     */
    private function mailbox(string $email, string $name): string
    {
        $name = $this->flatten($name);
        if ($name === '') {
            return $email;
        }

        if (preg_match('/^[\x20-\x7E]*$/', $name) !== 1) {
            return $this->encodeHeader($name) . ' <' . $email . '>';
        }

        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1) {
            $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }

        return $name . ' <' . $email . '>';
    }

    /** RFC 2047 encoded-word, but only when the value is not plain ASCII. */
    private function encodeHeader(string $value): string
    {
        $value = $this->flatten($value);
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function base64(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, self::CRLF), self::CRLF);
    }

    private function flatten(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    /**
     * @param list<int> $expect
     * @param string|null $echo what to record in the trace instead of the raw
     *                          line — credentials never land in the log
     */
    private function command(
        string $line,
        array $expect,
        string $phase,
        bool $check = true,
        bool $secret = false,
        ?string $echo = null,
    ): string {
        $this->note('> ' . ($secret ? ($echo ?? '*****') : ($echo ?? $line)));
        $this->write($line . self::CRLF);

        return $this->read($check ? $expect : [], $phase);
    }

    private function write(string $data): void
    {
        \assert($this->socket !== null);
        if (@fwrite($this->socket, $data) === false) {
            throw new MailException('The connection dropped while sending.', 'data', $this->conversation(), true);
        }
    }

    /**
     * Reads one complete SMTP reply — replies are multi-line, and the last
     * line is the one whose code is followed by a SPACE rather than a hyphen.
     * Stopping at the first line makes every subsequent read consume someone
     * else's answer, which desynchronises the whole session.
     *
     * @param list<int> $expect empty = accept anything
     */
    private function read(array $expect, string $phase): string
    {
        $socket = $this->socket;
        \assert($socket !== null);
        $lines = [];
        $code = 0;

        while (true) {
            $line = @fgets($socket, 1024);
            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];

                throw new MailException(
                    $timedOut
                        ? 'The mail server stopped responding (' . self::READ_TIMEOUT . 's).'
                        : 'The connection closed unexpectedly.',
                    $phase,
                    $this->conversation(),
                    true,
                );
            }
            $line = rtrim($line, "\r\n");
            $this->note('< ' . $line);
            $lines[] = substr($line, 4);
            $code = (int) substr($line, 0, 3);

            if (\strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($expect !== [] && !\in_array($code, $expect, true)) {
            throw new MailException(
                trim($code . ' ' . implode(' ', $lines)),
                $phase,
                $this->conversation(),
            );
        }

        return implode("\n", $lines);
    }

    /** @return resource */
    private function context(SmtpConfig $config): mixed
    {
        return stream_context_create(['ssl' => [
            // peer_name, not the IP we dialled: we pin the address for SSRF
            // safety and still verify the certificate against the real host.
            'peer_name' => $config->host,
            'verify_peer' => $config->verifyCert,
            'verify_peer_name' => $config->verifyCert,
            'allow_self_signed' => !$config->verifyCert,
            'SNI_enabled' => true,
        ]]);
    }

    /** @return non-empty-list<string> */
    private function resolve(string $host): array
    {
        try {
            return $this->vetter->vetAll($host);
        } catch (\InvalidArgumentException $e) {
            throw new MailException($e->getMessage(), 'connect', '', true, $e);
        }
    }

    private function authority(string $ip, int $port): string
    {
        return (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;
    }

    private function note(string $line): void
    {
        $this->trace[] = $line;
    }

    private function conversation(): string
    {
        $text = implode("\n", $this->trace);

        return mb_strlen($text) > self::TRACE_LIMIT
            ? mb_substr($text, -self::TRACE_LIMIT)
            : $text;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
