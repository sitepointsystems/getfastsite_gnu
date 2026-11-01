<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Shared\Net\HostVetter;
use Psr\Log\LoggerInterface;

/**
 * FTP / FTPS / SFTP over curl — hand-rolled in the S3Client and CurlCfTransport
 * spirit (no SDK, no new PHP extension). libcurl already speaks all three, so
 * one code path covers shared hosting (FTPS) and VPS/managed hosts (SFTP).
 *
 * Two properties are load-bearing:
 *
 * 1. ONE curl handle for the whole publish. FTP and FTPS pay a full login and
 *    TLS handshake per connection; curl reuses the connection when the handle
 *    is reused, which is the difference between a 20-second publish of a
 *    500-file site and a 10-minute one.
 * 2. Credentials go in CURLOPT_USERPWD, never on a command line — argv is
 *    world-readable via ps, the same reason GitPublisher passes its token
 *    through git's env-config instead of the URL.
 *
 * The protocol allowlist is pinned so a tampered site row cannot turn a publish
 * into an http:// or file:// request, and the host is vetted + IP-pinned
 * through the shared HostVetter so a self-serve `editor` cannot aim a publish
 * at the loopback interface or a cloud metadata endpoint.
 */
final class CurlFtpTransport implements FtpTransport
{
    private const CONNECT_TIMEOUT = 20;
    /** A probe does a login, maybe a TLS handshake, and a directory listing. */
    private const PROBE_TIMEOUT = 45;
    private const TRANSFER_TIMEOUT = 120;
    /** A dead wire dies in 60s rather than sitting out the full ceiling. */
    private const LOW_SPEED_TIME = 60;

    /**
     * libcurl CURLcode values. PHP's curl extension exposes only a SUBSET of
     * them as constants — CURLE_QUOTE_ERROR, CURLE_LOGIN_DENIED,
     * CURLE_REMOTE_ACCESS_DENIED, CURLE_REMOTE_FILE_NOT_FOUND and
     * CURLE_PEER_FAILED_VERIFICATION are all absent, and referencing one is a
     * fatal "undefined constant" on the exact error path that exists to explain
     * what went wrong. The numbers are stable ABI (curl's own enum) and safe to
     * pin. Verified against this build with defined(); phpstan flags the
     * missing ones, which is how these were caught.
     */
    private const E_REMOTE_ACCESS_DENIED = 9;
    private const E_QUOTE_ERROR = 21;
    /** Merged with CURLE_SSL_CACERT upstream in curl 7.62 — both are 60. */
    private const E_PEER_FAILED_VERIFICATION = 60;
    private const E_LOGIN_DENIED = 67;
    private const E_REMOTE_FILE_NOT_FOUND = 78;

    private ?FtpTarget $target = null;
    private ?\CurlHandle $handle = null;
    /** @var list<string> every vetted address for the host, IPv4 first. */
    private array $resolvedIps = [];
    /** Temp file holding the decrypted SFTP key for the life of the connection. */
    private ?string $keyFile = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HostVetter $hosts = new HostVetter(),
    ) {
    }

    public function open(FtpTarget $target): void
    {
        if ($target->host === '') {
            throw new \RuntimeException('No FTP host configured for this site.');
        }
        if (!\in_array($target->protocol, FtpTarget::PROTOCOLS, true)) {
            throw new \RuntimeException('Unsupported transfer protocol: ' . $target->protocol);
        }
        $this->assertProtocolSupported($target->protocol);

        $this->close();
        $this->target = $target;
        // Resolve + vet once per publish, then pin every request to that IP so
        // a rebind mid-upload cannot move the transfer to another machine.
        $this->resolvedIps = $this->hosts->vetAll($target->host);

        if ($target->privateKey !== '') {
            $this->keyFile = $this->writeKeyFile($target->privateKey);
        }
    }

    public function upload(string $remoteRelative, string $localFile): void
    {
        $target = $this->requireTarget();

        $size = @filesize($localFile);
        if ($size === false) {
            throw new \RuntimeException('Cannot read local file for upload: ' . $localFile);
        }
        $fh = @fopen($localFile, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open local file for upload: ' . $localFile);
        }

        try {
            $ch = $this->handle();
            $this->applyCommon($ch, $target->urlFor($remoteRelative));
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fh,
                CURLOPT_INFILESIZE => $size,
                // Shared hosts do not pre-create /media/{id}/ for us.
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
                CURLOPT_TIMEOUT => self::TRANSFER_TIMEOUT,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
            ]);

            if (curl_exec($ch) === false) {
                throw new \RuntimeException(sprintf(
                    'Upload of "%s" failed: %s',
                    $remoteRelative,
                    $this->errorFor($ch),
                ));
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // FTP answers 226/250 on a completed transfer; SFTP reports 0.
            if ($status !== 0 && ($status < 200 || $status >= 300)) {
                throw new \RuntimeException(sprintf('Upload of "%s" failed: server replied %d.', $remoteRelative, $status));
            }
        } finally {
            fclose($fh);
        }
    }

    public function delete(string $remoteRelative): void
    {
        $target = $this->requireTarget();
        $ch = $this->handle();

        // Quote commands act on the CONNECTION, not on a file, so the URL only
        // has to be a valid directory on the target — the command carries the
        // path itself. They also run BEFORE curl's CWD, which is why the path
        // is qualified rather than relative to the URL's directory. NOBODY
        // keeps curl from trying to retrieve anything.
        $path = $target->remoteFile($remoteRelative);
        $command = ($target->protocol === 'sftp' ? 'rm ' : 'DELE ') . $path;

        $this->applyCommon($ch, $target->urlFor('') . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_QUOTE => [$command],
            CURLOPT_TIMEOUT => self::TRANSFER_TIMEOUT,
        ]);

        if (curl_exec($ch) !== false) {
            return;
        }

        // Idempotent, like S3Client::delete — "already gone" IS the outcome we
        // wanted. Anything else must throw so the caller keeps the manifest
        // row and retries on the next publish instead of losing track of it.
        $errno = curl_errno($ch);
        $message = $this->errorFor($ch);
        if ($this->looksMissing($errno, $message)) {
            return;
        }

        throw new \RuntimeException(sprintf('Could not delete remote file "%s": %s', $remoteRelative, $message));
    }

    public function probe(): array
    {
        try {
            $target = $this->requireTarget();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'note' => $e->getMessage()];
        }

        $trace = fopen('php://temp', 'w+');

        try {
            $ch = $this->handle();
            // List the remote directory: proves DNS, TLS, credentials AND that
            // the remote path exists — the four things that actually go wrong.
            $this->applyCommon($ch, $target->urlFor('') . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::PROBE_TIMEOUT,
                CURLOPT_FTPLISTONLY => true,
                // A bare "timed out" says nothing an operator can act on. The
                // protocol conversation says everything: which address was
                // tried, whether login succeeded, whether it got as far as the
                // data connection.
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $trace,
            ]);

            $ok = curl_exec($ch) !== false;
            $log = $this->readTrace($trace);

            if (!$ok) {
                $this->logger->warning('FTP connection probe failed', [
                    'target' => $target->describe(),
                    'addresses' => $this->resolvedIps,
                    'trace' => $log,
                ]);

                // Use whichever diagnosis is MORE SPECIFIC, never both — two
                // explanations of one failure read as a contradiction.
                //
                // A named errno (rejected credentials, unverifiable
                // certificate, permission denied) already says exactly what to
                // do, and the phase read off the trace can only water it down:
                // preferring the phase unconditionally meant a certificate
                // mismatch reported the useless half ("stopped during login or
                // TLS") and swallowed the half naming the fix.
                $phase = $this->explainsItself(curl_errno($ch)) ? '' : $this->phaseOf($log, $target);
                $error = $this->errorFor($ch, hint: $phase === '');
                $note = $phase === '' ? $error : rtrim($error, ' .') . '. ' . ltrim($phase);

                return ['ok' => false, 'note' => $note, 'trace' => $log];
            }

            return [
                'ok' => true,
                'note' => 'Connected to ' . $target->describe() . ' and listed the remote directory.',
                'trace' => $log,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'note' => $e->getMessage(), 'trace' => $this->readTrace($trace)];
        } finally {
            if (\is_resource($trace)) {
                fclose($trace);
            }
        }
    }

    /**
     * @param resource|false $stream
     */
    private function readTrace(mixed $stream): string
    {
        if (!\is_resource($stream)) {
            return '';
        }
        rewind($stream);
        $log = (string) stream_get_contents($stream);

        // Never let a password reach a log line. curl does not print USERPWD,
        // but it DOES echo the PASS command it sends.
        return (string) preg_replace('/^(PASS)\s+.*$/mi', '$1 ********', $log);
    }

    /**
     * Whether this errno already carries a specific, actionable explanation —
     * in which case the phase read off the trace must not replace it.
     */
    private function explainsItself(int $errno): bool
    {
        return \in_array($errno, [
            self::E_LOGIN_DENIED,
            self::E_PEER_FAILED_VERIFICATION, // == CURLE_SSL_CACERT
            self::E_REMOTE_ACCESS_DENIED,
            CURLE_COULDNT_RESOLVE_HOST,
        ], true);
    }

    /**
     * Turn the protocol conversation into the one sentence an operator needs:
     * how far it got before it stopped. "Timed out" alone cannot distinguish a
     * wrong port from a NAT'd passive address, and those have opposite fixes.
     */
    private function phaseOf(string $trace, FtpTarget $target): string
    {
        if ($trace === '') {
            return '';
        }

        $loggedIn = preg_match('/^< 230\b/m', $trace) === 1;
        $passive = preg_match('/^(?:< 227|< 229|> (?:PASV|EPSV))\b/m', $trace) === 1;

        if ($loggedIn && $passive) {
            return sprintf(
                ' The login worked and it failed opening the DATA connection — that is normally a firewall blocking the passive port range on %s, or a passive address the server reports that is not reachable from here.',
                $target->host,
            );
        }
        if ($loggedIn) {
            return ' The login worked, so the credentials are right — it stopped after that.';
        }
        if (preg_match('/^< 220\b/m', $trace) === 1) {
            return ' It reached the server and stopped during login or TLS.';
        }

        // The TCP connection never completed, so nothing about FTP is at
        // fault yet. Which OTHER ports answer is the one fact that separates
        // "this host speaks a different protocol" from "this host is not
        // letting THIS machine in" — and those have opposite fixes.
        return sprintf(
            ' It never reached the server: the TCP connection to port %d timed out, before any FTP conversation began.%s',
            $target->effectivePort(),
            $this->reachabilitySummary($target),
        );
    }

    /**
     * Which of the usual transfer ports actually accept a connection from
     * here. Runs only after a failure, and only against the already-vetted
     * address.
     */
    private function reachabilitySummary(FtpTarget $target): string
    {
        $ip = $this->resolvedIps[0] ?? '';
        if ($ip === '') {
            return '';
        }

        $labels = [21 => 'FTP/FTPS', 22 => 'SFTP', 990 => 'implicit FTPS', 2222 => 'SFTP (alt)'];
        $open = [];
        $tried = 0;
        foreach ($labels as $port => $label) {
            if ($port === $target->effectivePort()) {
                continue; // already known to be dead
            }
            ++$tried;
            if ($this->tcpOpens($ip, $port)) {
                $open[] = $port . ' (' . $label . ')';
            }
        }

        if ($tried === 0) {
            return '';
        }

        if ($open !== []) {
            return sprintf(
                ' Port %s DOES answer from here, so the host is reachable and it is the protocol/port that is wrong — switch the Protocol setting to match, and check which one your desktop client is actually using.',
                implode(' and ', $open),
            );
        }

        // Nothing on this host answers. The remaining question — is the block
        // on our side or theirs? — is settled by whether ANY outbound FTP
        // works from here, and the two answers have completely different
        // owners: one is a hosting ticket, the other is the customer's
        // firewall.
        $summary = ' No other transfer port answers either (22, 990, 2222), so this is not a protocol mismatch.';

        return $summary . match ($this->egressAllowsFtp()) {
            false => ' This server cannot open an FTP control connection to ANY host — a known-good public FTP server on port 21 times out the same way — so the block is on THIS side. Ask whoever runs this server (or Coolify/the cloud firewall) to allow outbound TCP 21 and the passive port range.',
            true => ' This server CAN reach other FTP servers on port 21, so outbound FTP works here and the block is at the DESTINATION: that host is filtering port 21 by source address. Add this server\'s public IP to the allowlist in the host\'s firewall — a desktop client connects from a different address, which is why it is unaffected.',
            default => ' Whether outbound FTP works from this server at all could not be determined (the reference host did not resolve), so check both the egress firewall here and the source-IP allowlist on the destination.',
        };
    }

    /**
     * Whether an FTP control connection to anywhere is possible from here.
     * Deliberately a well-known public FTP service: the question is about this
     * machine's egress, not about the customer's host.
     *
     * @return bool|null null when the reference host cannot be resolved, so
     *                   the check itself is inconclusive rather than negative
     */
    private function egressAllowsFtp(): ?bool
    {
        $reference = gethostbyname('ftp.gnu.org');
        if ($reference === 'ftp.gnu.org' || filter_var($reference, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->tcpOpens($reference, 21, 8);
    }

    private function tcpOpens(string $ip, int $port, int $timeoutSeconds = 5): bool
    {
        $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $error,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    public function close(): void
    {
        if ($this->handle instanceof \CurlHandle) {
            curl_close($this->handle);
            $this->handle = null;
        }
        if ($this->keyFile !== null) {
            @unlink($this->keyFile);
            $this->keyFile = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function handle(): \CurlHandle
    {
        if (!$this->handle instanceof \CurlHandle) {
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException('Could not initialize the transfer.');
            }
            $this->handle = $ch;
        }

        // Reset per-request options that would otherwise leak between calls
        // (an upload's INFILE surviving into a delete would be a silent bug),
        // while keeping the handle — and therefore the connection — alive.
        curl_setopt_array($this->handle, [
            CURLOPT_UPLOAD => false,
            CURLOPT_NOBODY => false,
            CURLOPT_QUOTE => [],
            CURLOPT_FTPLISTONLY => false,
            CURLOPT_INFILESIZE => -1,
        ]);

        return $this->handle;
    }

    private function applyCommon(\CurlHandle $ch, string $url): void
    {
        $target = $this->requireTarget();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // Pinned so a tampered site row cannot turn a publish into an
            // http:// or file:// request.
            CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS | CURLPROTO_SFTP,
            CURLOPT_RETURNTRANSFER => true,
            // EVERY vetted address, IPv4 first, so curl can fail over exactly
            // as a normal resolver would. Pinning one address meant a host
            // whose AAAA record is unroutable from here timed out while the
            // same credentials worked instantly in a desktop client.
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target->host, $target->effectivePort(), implode(',', $this->resolvedIps))],
        ];

        if ($target->protocol !== 'sftp') {
            // THE reason a passive FTP transfer times out for us while
            // FileZilla sails through. A NAT'd server answers PASV with the
            // address IT sees itself on — routinely a 10.x/192.168.x LAN
            // address — and a client that believes it opens the data
            // connection to an unroutable host and waits out the timeout.
            // Desktop clients ignore that address by default; curl does not
            // unless told. Reuse the host we already reached on the control
            // connection, which is by definition routable from here.
            $options[CURLOPT_FTP_SKIP_PASV_IP] = true;
            // Passive throughout: an active-mode transfer needs the server to
            // dial back INTO this container, which no firewall permits.
            $options[CURLOPT_FTPPORT] = null;
        }

        if ($target->username !== '') {
            $options[CURLOPT_USERPWD] = $target->username . ':' . $target->password;
        }

        if ($target->protocol === 'ftps') {
            // Explicit TLS over the ftp:// scheme, required for BOTH the
            // control and data channels — encrypting only the control channel
            // would still send the file itself in the clear.
            $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
            // Verified by default: the password travels over this connection,
            // so a MITM here is a credential compromise, not just a tampered
            // upload. The per-site opt-out exists because a stock FTP server's
            // self-signed certificate — issued for its own IP, matching no
            // hostname — cannot be verified by anyone, and refusing outright
            // makes those hosts unusable. Encryption is kept either way; what
            // is given up is proof of who is on the other end.
            $options[CURLOPT_SSL_VERIFYPEER] = $target->verifyCertificate;
            $options[CURLOPT_SSL_VERIFYHOST] = $target->verifyCertificate ? 2 : 0;
        }

        if ($target->protocol === 'sftp' && $this->keyFile !== null) {
            $options[CURLOPT_SSH_PRIVATE_KEYFILE] = $this->keyFile;
        }

        curl_setopt_array($ch, $options);
    }

    private function requireTarget(): FtpTarget
    {
        return $this->target ?? throw new \RuntimeException('No transfer target has been opened.');
    }

    /**
     * The decrypted key has to reach libcurl as a FILE. Written 0600 and
     * removed on close(); the destructor is the backstop for a fatal mid-run.
     */
    private function writeKeyFile(string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fs-sftp-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the SFTP key.');
        }
        // Narrow the permissions BEFORE the secret lands in the file.
        @chmod($path, 0600);
        // OpenSSH rejects a key without a trailing newline.
        if (file_put_contents($path, rtrim($key, "\r\n") . "\n") === false) {
            @unlink($path);
            throw new \RuntimeException('Could not write the temporary SFTP key file.');
        }

        return $path;
    }

    /**
     * Whether a delete failure means "it was not there", which is a success.
     * curl surfaces this as a protocol-level quote-command error, so the code
     * alone is ambiguous and the server's own wording has to be consulted.
     */
    private function looksMissing(int $errno, string $message): bool
    {
        if ($errno !== self::E_QUOTE_ERROR && $errno !== self::E_REMOTE_FILE_NOT_FOUND) {
            return false;
        }

        $haystack = strtolower($message);
        foreach (['no such file', 'not found', 'does not exist', '550'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $hint append the generic per-errno explanation. Suppressed
     *                   when the caller has read a more specific phase off the
     *                   connection trace, so the two never contradict.
     */
    private function errorFor(\CurlHandle $ch, bool $hint = true): string
    {
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        // Translate the failures an operator can actually act on. A raw
        // "curl error 67" on a settings screen tells nobody anything.
        $explanation = !$hint ? '' : match ($errno) {
            self::E_LOGIN_DENIED => ' — the username or password was rejected by the host.',
            self::E_PEER_FAILED_VERIFICATION => ' — the host\'s TLS certificate could not be verified. Control panels (aaPanel, Plesk, cPanel) generate a self-signed certificate naming the server\'s own IP, which is unverifiable by anyone and which switching to that IP does not fix. Either use SFTP, which has no certificate at all, or untick "Verify the host\'s TLS certificate" on the Go-live card to accept it deliberately.',
            CURLE_COULDNT_CONNECT => ' — nothing accepted a connection on that port. Check the port and the host\'s firewall.',
            CURLE_COULDNT_RESOLVE_HOST => ' — that hostname does not resolve.',
            self::E_REMOTE_ACCESS_DENIED => ' — the account cannot write to that remote path.',
            CURLE_OPERATION_TIMEDOUT => ' — the host stopped responding.',
            default => '',
        };

        $this->logger->warning('FTP transfer error', ['errno' => $errno, 'error' => $error]);

        return ($error !== '' ? $error : 'transfer failed (curl error ' . $errno . ')') . $explanation;
    }

    /**
     * libcurl is compiled with a protocol set that varies by build. SFTP in
     * particular needs libssh2/libssh, which not every distro's package
     * includes — failing here with a clear message beats every publish dying
     * with an opaque "unsupported protocol".
     */
    private function assertProtocolSupported(string $protocol): void
    {
        $scheme = $protocol === 'sftp' ? 'sftp' : ($protocol === 'ftps' ? 'ftps' : 'ftp');
        if (\in_array($scheme, self::supportedProtocols(), true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'This server\'s libcurl was built without %s support (available: %s).',
            strtoupper($scheme),
            implode(', ', self::supportedProtocols()) ?: 'none',
        ));
    }

    /**
     * @return list<string>
     */
    public static function supportedProtocols(): array
    {
        $info = curl_version();
        /** @var list<string> $protocols */
        $protocols = \is_array($info) && isset($info['protocols']) && \is_array($info['protocols'])
            ? array_values(array_map('strval', $info['protocols']))
            : [];

        return $protocols;
    }
}
