<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Mail\MailException;
use Fastsite\Mail\Message;
use Fastsite\Mail\SmtpConfig;
use Fastsite\Mail\SmtpTransport;
use Fastsite\Shared\Net\HostVetter;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP client's two dangerous halves, tested without a mail server:
 * the multi-line reply parser (get it wrong and every later read consumes
 * someone else's answer) and message composition (get it wrong and a visitor
 * forges headers on the customer's domain).
 */
final class SmtpTransportTest extends TestCase
{
    private function transport(): SmtpTransport
    {
        return new SmtpTransport(new HostVetter());
    }

    /** Feeds scripted server output into the transport's own socket slot. */
    private function withServerSaying(string $wire): SmtpTransport
    {
        $transport = $this->transport();
        $socket = fopen('php://memory', 'r+');
        \assert($socket !== false);
        fwrite($socket, $wire);
        rewind($socket);

        $prop = new \ReflectionProperty($transport, 'socket');
        $prop->setValue($transport, $socket);

        return $transport;
    }

    /**
     * @param list<int> $expect
     */
    private function read(SmtpTransport $transport, array $expect, string $phase = 'test'): string
    {
        $method = new \ReflectionMethod($transport, 'read');

        return (string) $method->invoke($transport, $expect, $phase);
    }

    /**
     * A reply is multi-line and ends at the line whose code is followed by a
     * SPACE, not a hyphen. Stopping at the first line desynchronises the whole
     * session — every later command then reads the previous reply's tail.
     */
    public function testMultiLineReplyIsConsumedWhole(): void
    {
        $transport = $this->withServerSaying(
            "250-mail.example.com Hello\r\n250-SIZE 35882577\r\n250-STARTTLS\r\n250 AUTH LOGIN PLAIN\r\n"
            . "220 next reply\r\n",
        );

        $first = $this->read($transport, [250]);
        self::assertStringContainsString('STARTTLS', $first);
        self::assertStringContainsString('AUTH LOGIN PLAIN', $first);

        // The next read must land on the NEXT reply, not on a leftover line.
        $second = $this->read($transport, [220]);
        self::assertSame('next reply', $second);
    }

    public function testAnUnexpectedCodeThrowsWithTheServersOwnText(): void
    {
        $transport = $this->withServerSaying("535 5.7.8 Authentication credentials invalid\r\n");

        try {
            $this->read($transport, [235], 'auth');
            self::fail('a rejected code must throw');
        } catch (MailException $e) {
            self::assertSame('auth', $e->phase);
            self::assertStringContainsString('Authentication credentials invalid', $e->getMessage());
            self::assertStringContainsString('app password', $e->hint());
        }
    }

    public function testAClosedConnectionIsReportedAsATransportFailure(): void
    {
        // Empty stream = the server hung up without answering.
        $transport = $this->withServerSaying('');

        try {
            $this->read($transport, [220], 'greeting');
            self::fail('a dead connection must throw');
        } catch (MailException $e) {
            self::assertTrue($e->transport, 'no reply at all is a transport failure, worth retrying');
            self::assertSame('greeting', $e->phase);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(Message $message, SmtpConfig $config): string
    {
        $method = new \ReflectionMethod(SmtpTransport::class, 'compose');

        return (string) $method->invoke($this->transport(), $config, $message);
    }

    private function config(): SmtpConfig
    {
        return new SmtpConfig(
            host: 'smtp.example.com',
            port: 587,
            encryption: 'starttls',
            username: 'bob',
            password: 'secret',
            fromEmail: 'website@example.com',
            fromName: 'Example Site',
        );
    }

    /**
     * A visitor's name lands in a header. A bare newline in it would let them
     * append headers of their own — a Bcc to a list, a forged From.
     */
    public function testHeaderInjectionCannotEscapeIntoNewHeaders(): void
    {
        $message = Message::create(
            fromEmail: 'website@example.com',
            fromName: 'Site',
            to: ['leads@example.com'],
            subject: "Enquiry\r\nBcc: victim@example.com",
            textBody: 'hi',
            replyTo: 'ada@example.com',
            replyToName: "Ada\r\nX-Evil: yes",
        );

        $raw = $this->compose($message, $this->config());
        [$headers] = explode("\r\n\r\n", $raw, 2);

        // The invariant is not "that text is absent" — it survives harmlessly
        // as literal content, which is useful to see. It is that no HEADER
        // LINE was created from it.
        $names = [];
        foreach (explode("\r\n", $headers) as $line) {
            self::assertMatchesRegularExpression('/^[A-Za-z-]+: /', $line, 'every header line must be a real header');
            $names[] = strtolower(strtok($line, ':') ?: '');
        }
        self::assertNotContains('bcc', $names);
        self::assertNotContains('x-evil', $names);

        // And a display name carrying RFC 5322 specials is quoted, so no
        // lenient parser has to guess where the address begins.
        self::assertStringContainsString('Reply-To: "Ada  X-Evil: yes" <ada@example.com>', $headers);
    }

    public function testAnInvalidAddressIsDroppedRatherThanTrusted(): void
    {
        self::assertSame('', Message::addr("ada@example.com\r\nBcc: x@y.z"));
        self::assertSame('', Message::addr('not an address'));
        self::assertSame('ada@example.com', Message::addr('  ada@example.com '));
    }

    /**
     * Base64 for the body sidesteps the 998-octet line limit, dot-stuffing and
     * 8-bit content in one move. All three corrupt real form submissions.
     */
    public function testBodyIsBase64SoLongLinesAndLeadingDotsCannotCorruptIt(): void
    {
        $body = ".\n" . str_repeat('x', 5000) . "\n.\n";
        $raw = $this->compose(
            Message::create('website@example.com', 'Site', ['a@example.com'], 'Subject', $body),
            $this->config(),
        );

        [$headers, $encoded] = explode("\r\n\r\n", $raw, 2);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $headers);

        foreach (explode("\r\n", $encoded) as $line) {
            self::assertLessThanOrEqual(998, \strlen($line), 'no line may exceed the SMTP limit');
            self::assertStringStartsNotWith('.', $line, 'a leading dot would end the message early');
        }
        self::assertSame($body, base64_decode(str_replace("\r\n", '', $encoded), true));
    }

    public function testNonAsciiHeadersAreRfc2047EncodedAndAsciiOnesAreLeftReadable(): void
    {
        $raw = $this->compose(
            Message::create('website@example.com', 'Søren Ø', ['a@example.com'], 'Ny henvendelse fra Åse', 'x'),
            $this->config(),
        );

        self::assertStringContainsString('Subject: =?UTF-8?B?', $raw);
        self::assertStringContainsString('MIME-Version: 1.0', $raw);
        // A plain ASCII header must stay legible rather than being encoded.
        self::assertStringContainsString('To: a@example.com', $raw);
    }

    public function testAutoSubmittedHeaderIsSetSoVacationRepliesDoNotBounceBack(): void
    {
        $raw = $this->compose(
            Message::create('website@example.com', 'Site', ['a@example.com'], 'Subject', 'x'),
            $this->config(),
        );

        self::assertStringContainsString('Auto-Submitted: auto-generated', $raw);
    }

    public function testIpv6AddressesAreBracketedInTheConnectionAuthority(): void
    {
        $method = new \ReflectionMethod(SmtpTransport::class, 'authority');

        self::assertSame('[2001:db8::1]:587', $method->invoke($this->transport(), '2001:db8::1', 587));
        self::assertSame('198.51.100.4:25', $method->invoke($this->transport(), '198.51.100.4', 25));
    }

    /**
     * A self-serve editor sets the SMTP host, so it goes through the same
     * SSRF guard the FTP publisher and the URL importer use.
     */
    public function testAPrivateOrLoopbackHostIsRefused(): void
    {
        $config = new SmtpConfig(
            host: '127.0.0.1', port: 25, encryption: 'none',
            username: '', password: '', fromEmail: 'a@example.com', fromName: '',
        );

        try {
            $this->transport()->send($config, Message::create('a@example.com', '', ['b@example.com'], 's', 'b'));
            self::fail('loopback must be refused');
        } catch (MailException $e) {
            self::assertSame('connect', $e->phase);
            self::assertStringContainsString('private, loopback', $e->getMessage());
        }
    }

    public function testEveryPhaseHasItsOwnPlainLanguageHint(): void
    {
        $phases = ['config', 'connect', 'greeting', 'starttls', 'auth', 'envelope', 'data'];
        $hints = [];
        foreach ($phases as $phase) {
            $hint = (new MailException('x', $phase))->hint();
            self::assertNotSame('', $hint);
            $hints[] = $hint;
        }

        // Each phase must say something DIFFERENT — the whole point is that
        // "never reached the server" and "the password was rejected" stop
        // looking like the same failure. Notably `config` must not talk about
        // ports and firewalls when nothing has been filled in yet.
        self::assertCount(\count($phases), array_unique($hints));
        self::assertStringNotContainsString('port', (new MailException('x', 'config'))->hint());
    }
}
