<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

/**
 * One connection to a customer's own web host. An interface (the CfTransport
 * pattern) so FtpPublisher's mirror algorithm — which is where the safety
 * guarantees live — is testable against a fake without a real server.
 *
 * Implementations are STATEFUL and single-target: open() binds the connection
 * to one FtpTarget, and the connection is deliberately reused across every
 * upload in a publish. FTP and FTPS pay a full login (and TLS handshake) per
 * connection; opening one per file turns a 20-second publish into a 10-minute
 * one.
 */
interface FtpTransport
{
    /**
     * Bind to a target. Must be called before upload/delete. Implementations
     * may connect lazily, but must validate the target here.
     */
    public function open(FtpTarget $target): void;

    /**
     * Upload one local file to $remoteRelative (relative to the target's
     * remote path), creating intermediate directories as needed. Overwrites.
     *
     * @throws \RuntimeException on any failure — the caller must not record
     *                           the file as uploaded
     */
    public function upload(string $remoteRelative, string $localFile): void;

    /**
     * Remove one remote file. Idempotent: a file that is already gone is a
     * success, mirroring S3Client::delete — the point of the call is that the
     * path ends up absent.
     *
     * @throws \RuntimeException only on a real failure, so the caller keeps
     *                           the manifest row and retries next publish
     */
    public function delete(string $remoteRelative): void;

    /**
     * Cheap round trip proving credentials and the remote path work, for the
     * settings-screen "Test connection" button and the doctor check. Returns a
     * report rather than throwing, because both callers want to SHOW the
     * reason rather than blow up.
     *
     * @return array{ok: bool, note: string, trace?: string} `trace` is the
     *         protocol conversation, when the implementation can capture one
     */
    public function probe(): array;

    /** Release the connection. Safe to call when never opened. */
    public function close(): void;
}
