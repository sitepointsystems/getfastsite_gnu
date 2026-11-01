<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site SMTP: the account a site's form notifications are sent THROUGH.
 *
 * A published site is static, so it cannot send anything itself — the form
 * posts to the engine and the engine relays through these credentials. They
 * belong on the site row rather than in .env because each customer sends as
 * themselves: mail leaving their own server keeps their SPF/DKIM alignment,
 * and it means no customer's leads ever ride on our sending reputation.
 *
 * Follows the ftp_password_enc / cf_api_token_enc convention: non-secrets in
 * plaintext so the settings screen can show the target without decrypting,
 * the password in *_enc (TokenCipher — a replayable outbound secret, exactly
 * what that cipher is for).
 *
 * smtp_from_email is deliberately its OWN field and not the visitor's address.
 * Almost every provider rejects, or silently SPF-fails, a From it did not
 * authenticate — the visitor belongs in Reply-To. Getting that backwards is
 * the single most common reason contact-form mail lands in spam.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // Port 0 = "use the default for the chosen encryption" (25 none,
        // 465 tls, 587 starttls), so the common case needs no input and a
        // stored 0 never means "port zero".
        $db->executeStatement("ALTER TABLE sites ADD COLUMN smtp_host TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN smtp_port INTEGER NOT NULL DEFAULT 0');
        $db->executeStatement(
            "ALTER TABLE sites ADD COLUMN smtp_encryption TEXT NOT NULL DEFAULT 'starttls' CHECK (smtp_encryption IN ('none', 'tls', 'starttls'))",
        );
        $db->executeStatement("ALTER TABLE sites ADD COLUMN smtp_username TEXT NOT NULL DEFAULT ''");
        $db->executeStatement('ALTER TABLE sites ADD COLUMN smtp_password_enc TEXT');
        $db->executeStatement("ALTER TABLE sites ADD COLUMN smtp_from_email TEXT NOT NULL DEFAULT ''");
        $db->executeStatement("ALTER TABLE sites ADD COLUMN smtp_from_name TEXT NOT NULL DEFAULT ''");

        // Verification ON by default and worth keeping on: the account password
        // rides that connection, so an active MITM is a credential compromise.
        // The per-site opt-out exists for the same reason as ftp_verify_cert —
        // a stock mail box ships a self-signed certificate issued for its own
        // hostname, and refusing outright makes a large slice of real shared
        // hosting unusable. Turning it off keeps the connection encrypted; what
        // is given up is proof of who is on the other end.
        $db->executeStatement('ALTER TABLE sites ADD COLUMN smtp_verify_cert INTEGER NOT NULL DEFAULT 1');
    }
};
