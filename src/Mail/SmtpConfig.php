<?php

declare(strict_types=1);

namespace Fastsite\Mail;

use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Site\Dto\Site;

/**
 * Where a site's mail goes out through. Read off the site row, with the
 * password decrypted exactly once at the edge of the send.
 */
final readonly class SmtpConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $fromEmail,
        public string $fromName,
        public bool $verifyCert = true,
    ) {
    }

    public static function fromSite(Site $site, TokenCipher $cipher): self
    {
        $password = '';
        if ($site->smtpPasswordEnc !== null && $site->smtpPasswordEnc !== '') {
            $password = $cipher->decrypt($site->smtpPasswordEnc) ?? '';
        }

        return new self(
            host: $site->smtpHost,
            port: self::resolvePort($site->smtpPort, $site->smtpEncryption),
            encryption: $site->smtpEncryption,
            username: $site->smtpUsername,
            password: $password,
            fromEmail: $site->smtpFromEmail,
            fromName: $site->smtpFromName !== '' ? $site->smtpFromName : $site->name,
            verifyCert: $site->smtpVerifyCert,
        );
    }

    /**
     * A stored 0 means "whatever this encryption normally uses", so the common
     * case needs no input and nobody has to remember that STARTTLS is 587.
     */
    public static function resolvePort(int $port, string $encryption): int
    {
        if ($port > 0) {
            return $port;
        }

        return match ($encryption) {
            'tls' => 465,
            'starttls' => 587,
            default => 25,
        };
    }

    /**
     * Enough to attempt a send. The password is NOT required: some relays
     * authorise by IP, and a missing credential has to surface as the server's
     * own authentication error rather than as a silent "not configured" skip
     * that looks like the form was never submitted.
     */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->fromEmail !== '';
    }
}
