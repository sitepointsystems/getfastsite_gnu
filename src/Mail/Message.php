<?php

declare(strict_types=1);

namespace Fastsite\Mail;

/**
 * One outbound message, already sanitised for the wire.
 *
 * Every header value is stripped of CR and LF at construction. That is not
 * defensive tidiness: header values here carry visitor-supplied text (a name
 * in a Reply-To display name, a subject built from a form field), and a bare
 * newline in one of them lets the sender append headers of their own — a Bcc
 * to a list, a forged From. The strip happens once, here, so no call site can
 * forget it.
 */
final readonly class Message
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public array $to,
        public string $subject,
        public string $textBody,
        public string $htmlBody = '',
        public string $replyTo = '',
        public string $replyToName = '',
        public array $cc = [],
    ) {
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public static function create(
        string $fromEmail,
        string $fromName,
        array $to,
        string $subject,
        string $textBody,
        string $htmlBody = '',
        string $replyTo = '',
        string $replyToName = '',
        array $cc = [],
    ): self {
        return new self(
            fromEmail: self::addr($fromEmail),
            fromName: self::header($fromName),
            to: self::addrList($to),
            subject: self::header($subject),
            textBody: $textBody,
            htmlBody: $htmlBody,
            replyTo: self::addr($replyTo),
            replyToName: self::header($replyToName),
            cc: self::addrList($cc),
        );
    }

    /** Strips anything that could start a new header line, then clamps length. */
    public static function header(string $value): string
    {
        $flat = str_replace(["\r", "\n", "\0"], ' ', $value);

        return trim(mb_substr($flat, 0, 500));
    }

    /**
     * An address, or '' when it is not one. Validation is the injection guard
     * too — FILTER_VALIDATE_EMAIL rejects anything carrying a newline, so a
     * value that survives this is safe to write into an envelope command.
     */
    public static function addr(string $value): string
    {
        $value = trim(str_replace(["\r", "\n", "\0"], '', $value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    public static function addrList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $clean = self::addr((string) $value);
            if ($clean !== '' && !\in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Every envelope recipient: To + Cc.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_unique([...$this->to, ...$this->cc]));
    }
}
