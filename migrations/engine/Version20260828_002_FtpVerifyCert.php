<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-site escape hatch for FTPS hosts whose certificate cannot verify.
 *
 * Verification is ON by default and should stay on: the account password
 * travels over that connection, so an active MITM there is a credential
 * compromise rather than merely a tampered upload.
 *
 * But the default certificate on a stock Pure-FTPd/vsftpd box is self-signed
 * and issued for the machine's own IP, which no hostname can ever match — and
 * that describes a large share of the shared hosting customers actually use.
 * Desktop clients paper over this by prompting once and remembering the click;
 * with no equivalent, those hosts are simply unusable. The honest options are
 * SFTP (no certificate involved) or an explicit, per-site, clearly-labelled
 * decision to accept the certificate — never a silent global relaxation.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'ALTER TABLE sites ADD COLUMN ftp_verify_cert INTEGER NOT NULL DEFAULT 1',
        );
    }
};
