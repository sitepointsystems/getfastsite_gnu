<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Uploads a site's build/ output to the CUSTOMER'S OWN web host.
 *
 * This is the mode that makes us a deploy service rather than a hosting
 * service: the files live on hosting the customer controls, so the site
 * survives us. Git mode diffs the build against a local clone; here the far
 * side is a server we cannot cheaply hash, so the diff is driven by
 * `ftp_manifest` in the site DB — one row per path WE have uploaded.
 *
 * That manifest is also the safety guarantee. The remote directory frequently
 * already holds something: an existing site, a webmail folder, the host's own
 * index.html. Orphan deletion is scoped to rows in the manifest, so a path we
 * never uploaded is never deleted — no matter what is sitting next to ours.
 */
final class FtpPublisher
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly SiteRepository $sites,
        private readonly FtpTransport $transport,
        private readonly SecretScanner $secrets,
        private readonly TokenCipher $cipher,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(): void|null $tick progress heartbeat for the queue watchdog
     *
     * @return array{uploaded: int, skipped: int, deleted: int, bytes: int, target: string}
     */
    public function publish(Site $site, ?\Closure $tick = null): array
    {
        $tick ??= static function (): void {};

        $buildDir = $this->databases->sitePath($site->handle) . '/build';
        if (!is_dir($buildDir)) {
            throw new \RuntimeException(sprintf('Site "%s" has no build output — run build first.', $site->handle));
        }
        if (!$site->ftpConfigured()) {
            throw new \RuntimeException(sprintf('Site "%s" has no FTP host/username configured.', $site->handle));
        }

        // An uploaded dist is exactly as public as a pushed one, so it gets the
        // same scan. Failing here means nothing reaches the customer's host.
        $this->secrets->assertNoSecrets($buildDir, skipGit: false, what: 'Nothing was uploaded.');
        $tick();

        $target = $this->targetFor($site);
        $db = $this->databases->site($site->handle);

        // A changed destination invalidates the manifest: it describes files on
        // a server we are no longer talking to. Deleting "orphans" against the
        // new host would be deleting paths that were never ours — so the
        // manifest is discarded and this run uploads everything and deletes
        // nothing.
        $retargeted = $site->ftpTargetKey !== $target->targetKey();
        if ($retargeted) {
            $db->executeStatement('DELETE FROM ftp_manifest');
            $this->logger->info('FTP target changed — remote manifest reset', [
                'site' => $site->handle,
                'target' => $target->describe(),
            ]);
        }

        /** @var array<string, string> $manifest remote path => sha256 */
        $manifest = array_column(
            $db->fetchAllAssociative('SELECT remote_path, sha256 FROM ftp_manifest'),
            'sha256',
            'remote_path',
        );

        $this->transport->open($target);
        $uploaded = 0;
        $skipped = 0;
        $bytes = 0;

        try {
            foreach ($this->secrets->relativeFiles($buildDir) as $relative) {
                $local = $buildDir . '/' . $relative;
                $hash = hash_file('sha256', $local);
                if ($hash === false) {
                    throw new \RuntimeException('Could not hash build file: ' . $relative);
                }

                if (($manifest[$relative] ?? null) === $hash) {
                    ++$skipped;
                    unset($manifest[$relative]);
                    $tick();
                    continue;
                }

                $this->transport->upload($relative, $local);

                // The row is recorded only AFTER a successful upload — the same
                // ordering BuildService::writeBatch uses. A failed transfer
                // must never be skipped as "unchanged" on the retry.
                $size = (int) @filesize($local);
                $db->executeStatement(
                    'INSERT INTO ftp_manifest (remote_path, sha256, size, uploaded_at)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT (remote_path) DO UPDATE
                     SET sha256 = excluded.sha256, size = excluded.size, uploaded_at = excluded.uploaded_at',
                    [$relative, $hash, $size, $this->clock->iso()],
                );

                ++$uploaded;
                $bytes += $size;
                unset($manifest[$relative]);
                $tick();
            }

            // Whatever is left was uploaded by us before and is not in this
            // build. Anything we never uploaded is not in the manifest and is
            // therefore untouched.
            $deleted = $retargeted ? 0 : $this->deleteOrphans($db, array_keys($manifest), $tick);
        } finally {
            $this->transport->close();
        }

        if ($retargeted) {
            $this->sites->update($site->id, ['ftp_target_key' => $target->targetKey()]);
        }

        $this->logger->info('Published over ' . $target->protocol, [
            'site' => $site->handle,
            'uploaded' => $uploaded,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'bytes' => $bytes,
        ]);

        return [
            'uploaded' => $uploaded,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'bytes' => $bytes,
            'target' => $target->describe(),
        ];
    }

    /**
     * Connection check for the settings screen and doctor. Never throws — both
     * callers want to show the reason.
     *
     * @return array{ok: bool, note: string, trace?: string}
     */
    public function test(Site $site): array
    {
        if (!$site->ftpConfigured()) {
            return ['ok' => false, 'note' => 'Enter a host and username first.'];
        }

        try {
            $this->transport->open($this->targetFor($site));

            return $this->transport->probe();
        } catch (\Throwable $e) {
            return ['ok' => false, 'note' => $e->getMessage()];
        } finally {
            $this->transport->close();
        }
    }

    /**
     * @param list<string> $orphans
     * @param \Closure(): void $tick
     */
    private function deleteOrphans(\Doctrine\DBAL\Connection $db, array $orphans, \Closure $tick): int
    {
        $deleted = 0;
        foreach ($orphans as $path) {
            try {
                $this->transport->delete($path);
            } catch (\Throwable $e) {
                // Keep the row so the orphan is retried next publish rather
                // than being forgotten while still live on the customer's host
                // — the same call the build makes on a failed unlink().
                $this->logger->warning('Could not delete remote orphan', ['path' => $path, 'error' => $e->getMessage()]);
                continue;
            }
            $db->executeStatement('DELETE FROM ftp_manifest WHERE remote_path = ?', [$path]);
            ++$deleted;
            $tick();
        }

        return $deleted;
    }

    private function targetFor(Site $site): FtpTarget
    {
        $password = $site->ftpPasswordEnc !== null ? (string) $this->cipher->decrypt($site->ftpPasswordEnc) : '';
        $key = $site->ftpKeyEnc !== null ? (string) $this->cipher->decrypt($site->ftpKeyEnc) : '';

        return FtpTarget::fromSite($site, $password, $key);
    }
}
