<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * What we have uploaded to the customer's own host, and nothing else.
 *
 * Deliberately NOT build_manifest. That table records what is on local disk and
 * covers only the rendered text outputs — media and files are copied outside it
 * — whereas a remote drifts independently of the build (a failed mid-run upload,
 * a customer deleting a file by hand, a second target).
 *
 * It is also the safety guarantee: the remote directory may already hold a live
 * site, a webmail folder or the host's own index.html. Orphan deletion is scoped
 * to rows in THIS table, so a path we never uploaded is never touched.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement(
            'CREATE TABLE ftp_manifest (
                remote_path TEXT PRIMARY KEY,
                sha256      TEXT NOT NULL,
                size        INTEGER NOT NULL,
                uploaded_at TEXT NOT NULL
            ) STRICT',
        );
    }
};
