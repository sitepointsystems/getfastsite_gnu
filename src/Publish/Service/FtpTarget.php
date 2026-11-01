<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Site\Dto\Site;

/**
 * Where an `ftp` site publishes to — the customer's own web host, resolved
 * from the site row with its secrets already decrypted.
 *
 * Built once per publish so the transport never touches the cipher or the DB,
 * which is what lets the whole upload run against a faked transport in tests.
 */
final readonly class FtpTarget
{
    public const PROTOCOLS = ['ftp', 'ftps', 'sftp'];

    public function __construct(
        public string $protocol,
        public string $host,
        public int $port,
        public string $username,
        public string $password = '',
        public string $privateKey = '',
        public string $remotePath = '',
        /**
         * FTPS only. False accepts the host's certificate without verifying
         * it — still encrypted, but no longer proof of WHO is on the other
         * end. See the migration for why the option has to exist.
         */
        public bool $verifyCertificate = true,
    ) {
    }

    /**
     * @param string $password decrypted; '' when none is stored
     * @param string $privateKey decrypted SFTP private key; '' when none
     */
    public static function fromSite(Site $site, string $password = '', string $privateKey = ''): self
    {
        return new self(
            protocol: \in_array($site->ftpProtocol, self::PROTOCOLS, true) ? $site->ftpProtocol : 'sftp',
            host: $site->ftpHost,
            port: $site->ftpPort,
            username: $site->ftpUsername,
            password: $password,
            privateKey: $privateKey,
            remotePath: self::normalizePath($site->ftpRemotePath),
            verifyCertificate: $site->ftpVerifyCert,
        );
    }

    /**
     * A remote directory is stored as an absolute-ish POSIX path with no
     * trailing slash ('' means the login directory). Backslashes are folded
     * because operators paste Windows-looking paths out of hosting panels.
     */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }

    public function effectivePort(): int
    {
        if ($this->port > 0) {
            return $this->port;
        }

        return $this->protocol === 'sftp' ? 22 : 21;
    }

    /** The curl URL scheme. FTPS is explicit-TLS over the ftp:// scheme. */
    public function scheme(): string
    {
        return $this->protocol === 'sftp' ? 'sftp' : 'ftp';
    }

    /**
     * Absolute URL for one remote file. curl treats a path after the host as
     * relative to the login directory for FTP but absolute for SFTP; storing
     * the remote path with a leading slash and letting the operator match
     * their host's convention is the only thing that works across both.
     */
    public function urlFor(string $relative): string
    {
        $base = sprintf('%s://%s:%d', $this->scheme(), $this->host, $this->effectivePort());
        $dir = $this->remotePath === '' ? '' : $this->remotePath;
        $path = $dir . '/' . ltrim($relative, '/');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * The path to name inside a quote command (FTP `DELE`, SFTP `rm`).
     *
     * These run BEFORE curl's CWD — `CURLOPT_QUOTE` is "prior to the request",
     * CURLOPT_PREQUOTE is the post-CWD one — so the path must NOT be relative
     * to the URL's directory. With a remote path configured it is qualified
     * with it; without one (publishing straight into the login directory) it
     * stays relative, because a leading slash there would address the server's
     * filesystem root instead.
     */
    public function remoteFile(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return $this->remotePath === '' ? $relative : $this->remotePath . '/' . $relative;
    }

    /**
     * Identity of the destination. When this changes the site points at a
     * different server, so the remote manifest describes a host we no longer
     * talk to. Credentials are deliberately excluded — rotating a password is
     * not a new destination and must not discard the manifest.
     */
    public function targetKey(): string
    {
        return hash('sha256', implode("\x00", [
            $this->protocol,
            strtolower($this->host),
            (string) $this->effectivePort(),
            $this->username,
            $this->remotePath,
        ]));
    }

    /** Host:port, safe to show in a flash message or a log (no credentials). */
    public function describe(): string
    {
        return sprintf(
            '%s://%s@%s:%d%s',
            $this->protocol,
            $this->username,
            $this->host,
            $this->effectivePort(),
            $this->remotePath === '' ? '/' : $this->remotePath,
        );
    }
}
