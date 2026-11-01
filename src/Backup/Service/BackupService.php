<?php

declare(strict_types=1);

namespace Fastsite\Backup\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fastsite\Backup\Storage\StorageClient;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;

/**
 * Backup + restore of engine.db, every site.db, media, and shell to object
 * storage (R2 in prod, a local directory in dev/test). DB snapshots go
 * through `VACUUM INTO` (a consistent standalone copy, no WAL) then gzip;
 * media is content-addressed (blob per sha256, cheap incremental) with a
 * per-run manifest; shell is a zip.
 *
 * Restore never lets the DB and the deployed dist diverge: after swapping a
 * restored site.db in, it always enqueues a full rebuild + publish, so the
 * live site is regenerated from exactly the restored rows.
 */
final class BackupService
{
    /** Newest N snapshots kept per (scope, site, kind); older are pruned. */
    private const RETAIN_PER_GROUP = 30;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly StorageClient $storage,
        private readonly JobQueue $queue,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---- Backup -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function backupEngine(string $source = 'schedule'): array
    {
        $db = $this->databases->engine();
        $this->databases->checkpoint($db);
        $gz = $this->snapshotDb($db);
        $key = 'engine/db/' . $this->stamp() . '.db.gz';
        $this->storage->put($key, $gz);

        return $this->record('engine', null, 'db', $key, $gz, $source);
    }

    /**
     * Full site backup: db + media + shell. Each part is independent — a
     * media failure never blocks the db snapshot (the important one).
     *
     * @return list<array<string, mixed>>
     */
    public function backupSite(Site $site, string $source = 'schedule'): array
    {
        $records = [$this->backupSiteDb($site, $source)];

        foreach (['media' => fn () => $this->backupSiteMedia($site, $source), 'shell' => fn () => $this->backupSiteShell($site, $source)] as $kind => $fn) {
            try {
                $record = $fn();
                if ($record !== null) {
                    $records[] = $record;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Backup part failed', ['site' => $site->handle, 'kind' => $kind, 'error' => $e->getMessage()]);
            }
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    public function backupSiteDb(Site $site, string $source = 'schedule'): array
    {
        $db = $this->databases->site($site->handle);
        $this->databases->checkpoint($db);
        $gz = $this->snapshotDb($db);
        $key = 'site/' . $site->handle . '/db/' . $this->stamp() . '.db.gz';
        $this->storage->put($key, $gz);

        return $this->record('site', $site->handle, 'db', $key, $gz, $source);
    }

    /**
     * Content-addressed media backup: every file becomes a blob keyed by its
     * sha256 (uploaded only if absent — natural incremental + dedupe), plus a
     * gzipped manifest mapping relative paths to shas for restore.
     *
     * @return array<string, mixed>|null null when the site has no media
     */
    public function backupSiteMedia(Site $site, string $source = 'schedule'): ?array
    {
        $mediaDir = $this->databases->sitePath($site->handle) . '/media';
        if (!is_dir($mediaDir)) {
            return null;
        }

        $manifest = [];
        $uploaded = 0;
        foreach ($this->filesUnder($mediaDir) as $relative => $absolute) {
            // Hash the bytes actually read so a blob's content always
            // matches its key; an unreadable file is skipped, never stored
            // as an empty blob under a wrong sha (that would poison the
            // exists() dedupe forever).
            $bytes = @file_get_contents($absolute);
            if ($bytes === false) {
                $this->logger->error('Media file unreadable during backup — skipped', ['site' => $site->handle, 'path' => $relative]);
                continue;
            }
            $sha = hash('sha256', $bytes);
            $blobKey = 'site/' . $site->handle . '/media/blob/' . $sha;
            if (!$this->storage->exists($blobKey)) {
                $this->storage->put($blobKey, $bytes);
                ++$uploaded;
            }
            $manifest[] = ['path' => $relative, 'sha256' => $sha, 'bytes' => \strlen($bytes)];
        }

        if ($manifest === []) {
            return null;
        }

        $gz = (string) gzencode(json_encode(['media' => $manifest], JSON_THROW_ON_ERROR), 6);
        $key = 'site/' . $site->handle . '/media/' . $this->stamp() . '.manifest.json.gz';
        $this->storage->put($key, $gz);
        $this->logger->info('Media backed up', ['site' => $site->handle, 'files' => \count($manifest), 'new_blobs' => $uploaded]);

        return $this->record('site', $site->handle, 'media', $key, $gz, $source);
    }

    /**
     * @return array<string, mixed>|null null when the site has no shell files
     */
    public function backupSiteShell(Site $site, string $source = 'schedule'): ?array
    {
        $shellDir = $this->databases->sitePath($site->handle) . '/shell';
        if (!is_dir($shellDir)) {
            return null;
        }

        $files = $this->filesUnder($shellDir);
        if ($files === []) {
            return null;
        }

        $tmpZip = $this->tmpPath('shell', 'zip');
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create shell zip for ' . $site->handle);
        }
        foreach ($files as $relative => $absolute) {
            $zip->addFile($absolute, $relative);
        }
        $zip->close();
        @chmod($tmpZip, 0600);

        try {
            $bytes = (string) file_get_contents($tmpZip);
            $key = 'site/' . $site->handle . '/shell/' . $this->stamp() . '.zip';
            $this->storage->put($key, $bytes);

            return $this->record('site', $site->handle, 'shell', $key, $bytes, $source);
        } finally {
            @unlink($tmpZip);
        }
    }

    // ---- Restore ----------------------------------------------------------

    /**
     * Restores a site's DB from a snapshot (latest if $storageKey omitted),
     * keeps the displaced live DB as .pre-restore-{ts}, and enqueues a full
     * rebuild+publish so the dist matches the restored rows exactly.
     *
     * @return array<string, mixed>
     */
    public function restoreSiteDb(Site $site, ?string $storageKey = null): array
    {
        if ($storageKey !== null) {
            // A caller may only restore a site from that SITE's own backups —
            // authz checks the destination, this pins the source (no cross-
            // tenant or engine-snapshot keys smuggled in via db_key).
            $this->assertKeyScope($storageKey, 'site/' . $site->handle . '/db/');
        }
        $key = $storageKey ?? $this->latestKey('site', $site->handle, 'db')
            ?? throw new \RuntimeException('No DB backup found for site ' . $site->handle);

        $raw = $this->fetchDb($key);
        $restored = $this->swapDb(
            $this->databases->sitePath($site->handle) . '/site.db',
            $raw,
            function () use ($site): void {
                if ($this->databases->isSiteOpen($site->handle)) {
                    $this->foldWal($this->databases->site($site->handle));
                }
                $this->databases->closeSite($site->handle);
            },
        );

        // Reopen + integrity + WAL truncate on the live copy.
        $check = (string) $this->databases->site($site->handle)->fetchOne('PRAGMA quick_check');
        if ($check !== 'ok') {
            throw new \RuntimeException('Restored site DB failed integrity check: ' . $check);
        }
        $this->databases->checkpoint($this->databases->site($site->handle));

        $jobUuid = $this->queue->enqueue(
            type: 'build_full',
            siteHandle: $site->handle,
            payload: ['trigger' => 'restore'],
            queue: 'high',
            dedupeKey: 'build:' . $site->handle,
            chain: \Fastsite\Build\Service\PublishTrigger::publishChain($site, 'restore: rebuild from backup ' . basename($key)),
        );

        $this->logger->warning('Site DB restored', ['site' => $site->handle, 'from' => $key, 'preserved' => $restored, 'rebuild' => $jobUuid]);

        return ['site' => $site->handle, 'restored_from' => $key, 'preserved_as' => $restored, 'rebuild_job' => $jobUuid];
    }

    /**
     * Restores media files listed in a manifest (latest if omitted). Only
     * writes files that are missing or whose sha differs, so it is safe to
     * re-run. Existing files not in the manifest are left untouched.
     *
     * @return array{restored: int, skipped: int}
     */
    public function restoreSiteMedia(Site $site, ?string $manifestKey = null): array
    {
        if ($manifestKey !== null) {
            $this->assertKeyScope($manifestKey, 'site/' . $site->handle . '/media/');
        }
        $key = $manifestKey ?? $this->latestKey('site', $site->handle, 'media')
            ?? throw new \RuntimeException('No media backup found for site ' . $site->handle);

        $gz = $this->storage->get($key) ?? throw new \RuntimeException('Media manifest missing: ' . $key);
        $json = @gzdecode($gz);
        if ($json === false) {
            throw new \RuntimeException('Corrupt media manifest: ' . $key);
        }
        /** @var array{media?: list<array{path: string, sha256: string, bytes: int}>} $manifest */
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $mediaDir = $this->databases->sitePath($site->handle) . '/media';

        $restored = 0;
        $skipped = 0;
        foreach ($manifest['media'] ?? [] as $entry) {
            $target = $mediaDir . '/' . $this->safeRelative($entry['path']);
            if (is_file($target) && hash_file('sha256', $target) === $entry['sha256']) {
                ++$skipped;
                continue;
            }
            $blob = $this->storage->get('site/' . $site->handle . '/media/blob/' . $entry['sha256']);
            if ($blob === null) {
                $this->logger->error('Media blob missing during restore', ['site' => $site->handle, 'sha' => $entry['sha256']]);
                continue;
            }
            // Same integrity discipline as DB restores: never write bytes
            // that don't hash to what the manifest promised (bit-rot or a
            // truncated object must not ship as "restored" media).
            if (hash('sha256', $blob) !== $entry['sha256']) {
                $this->logger->error('Media blob corrupt during restore (sha mismatch)', ['site' => $site->handle, 'sha' => $entry['sha256']]);
                continue;
            }
            $dir = \dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            if (file_put_contents($target, $blob) !== \strlen($blob)) {
                throw new \RuntimeException('Short write restoring media file: ' . $entry['path']);
            }
            ++$restored;
        }

        $this->logger->info('Media restored', ['site' => $site->handle, 'restored' => $restored, 'skipped' => $skipped]);

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * Restores shell files from a zip backup (latest if omitted) into the
     * site's shell dir. Overwrites files present in the zip; leaves others.
     * Callers that want the change live should rebuild afterwards.
     *
     * @return int files written
     */
    public function restoreSiteShell(Site $site, ?string $key = null): int
    {
        if ($key !== null) {
            $this->assertKeyScope($key, 'site/' . $site->handle . '/shell/');
        }
        $key ??= $this->latestKey('site', $site->handle, 'shell')
            ?? throw new \RuntimeException('No shell backup found for site ' . $site->handle);

        $bytes = $this->storage->get($key) ?? throw new \RuntimeException('Shell backup missing: ' . $key);
        $tmpZip = $this->tmpPath('shell-restore', 'zip');
        file_put_contents($tmpZip, $bytes);
        @chmod($tmpZip, 0600);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new \RuntimeException('Corrupt shell backup: ' . $key);
            }
            $shellDir = $this->databases->sitePath($site->handle) . '/shell';
            $restored = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $shellDir . '/' . $this->safeRelative($name);
                $dir = \dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                file_put_contents($target, stream_get_contents($stream));
                fclose($stream);
                ++$restored;
            }
            $zip->close();
            $this->logger->info('Shell restored', ['site' => $site->handle, 'files' => $restored]);

            return $restored;
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * Disaster recovery for engine.db. CLI-only (never a queued job or MCP
     * tool): the caller is responsible for stopping workers first. Displaced
     * DB kept as .pre-restore-{ts}.
     *
     * @return array<string, mixed>
     */
    public function restoreEngine(?string $storageKey = null): array
    {
        if ($storageKey !== null) {
            $this->assertKeyScope($storageKey, 'engine/db/');
        }
        $key = $storageKey ?? $this->latestKey('engine', null, 'db')
            ?? throw new \RuntimeException('No engine DB backup found');

        $raw = $this->fetchDb($key);
        $preserved = $this->swapDb(
            $this->databases->dataPath() . '/engine/engine.db',
            $raw,
            function (): void {
                if ($this->databases->isEngineOpen()) {
                    $this->foldWal($this->databases->engine());
                }
                $this->databases->closeAll();
            },
        );

        $check = (string) $this->databases->engine()->fetchOne('PRAGMA quick_check');
        if ($check !== 'ok') {
            throw new \RuntimeException('Restored engine DB failed integrity check: ' . $check);
        }
        $this->databases->checkpoint($this->databases->engine());

        return ['restored_from' => $key, 'preserved_as' => $preserved];
    }

    // ---- Listing / retention ---------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $siteHandle = null, int $limit = 50): array
    {
        $sql = 'SELECT id, scope, site_handle, kind, storage_key, size_bytes, trigger_source, status, created_at FROM backups';
        $params = [];
        if ($siteHandle !== null) {
            $sql .= ' WHERE site_handle = ?';
            $params[] = $siteHandle;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit);

        return $this->databases->engine()->fetchAllAssociative($sql, $params);
    }

    /**
     * Keeps the newest RETAIN_PER_GROUP snapshots per (scope, site, kind),
     * deleting older objects + rows. Media blobs are content-addressed and
     * shared across manifests, so they are never GC'd here (manifests only).
     *
     * @return array{deleted: int}
     */
    public function prune(): array
    {
        $db = $this->databases->engine();
        $groups = $db->fetchAllAssociative(
            'SELECT scope, site_handle, kind FROM backups GROUP BY scope, site_handle, kind',
        );

        // Never delete an object a queued/running restore explicitly targets.
        $reserved = [];
        $restoreJobs = $db->fetchAllAssociative(
            "SELECT payload FROM jobs WHERE type = 'restore_site' AND status IN ('pending', 'running')",
        );
        foreach ($restoreJobs as $jobRow) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $jobRow['payload'], true) ?: [];
            foreach (['db_key', 'media_key', 'shell_key'] as $field) {
                if (isset($payload[$field]) && \is_string($payload[$field])) {
                    $reserved[$payload[$field]] = true;
                }
            }
        }

        $deleted = 0;
        foreach ($groups as $group) {
            $rows = $db->fetchAllAssociative(
                'SELECT id, storage_key FROM backups
                 WHERE scope = ? AND kind = ? AND site_handle IS ?
                 ORDER BY id DESC',
                [$group['scope'], $group['kind'], $group['site_handle']],
            );
            foreach (\array_slice($rows, self::RETAIN_PER_GROUP) as $old) {
                $key = (string) $old['storage_key'];
                if (isset($reserved[$key])) {
                    $this->logger->info('Prune skipped a key an in-flight restore targets', ['key' => $key]);
                    continue;
                }
                try {
                    $this->storage->delete($key);
                } catch (\Throwable $e) {
                    // Keep the row so the next prune retries the object —
                    // deleting it now would orphan the object in storage.
                    $this->logger->error('Prune delete failed — will retry next run', ['key' => $key, 'error' => $e->getMessage()]);
                    continue;
                }
                $db->executeStatement('DELETE FROM backups WHERE id = ?', [(int) $old['id']]);
                ++$deleted;
            }
        }

        if ($deleted > 0) {
            $this->logger->info('Pruned old backups', ['deleted' => $deleted]);
        }

        return ['deleted' => $deleted];
    }

    // ---- Internals --------------------------------------------------------

    /** VACUUM INTO a temp file, gzip its bytes, delete the temp. */
    private function snapshotDb(Connection $db): string
    {
        $tmp = $this->tmpPath('db', 'db');
        // VACUUM INTO wants the target absent and takes a string literal path.
        @unlink($tmp);
        $db->executeStatement("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
        @chmod($tmp, 0600);
        try {
            $bytes = file_get_contents($tmp);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('VACUUM INTO produced no snapshot bytes.');
            }

            return (string) gzencode($bytes, 6);
        } finally {
            @unlink($tmp);
        }
    }

    /** Downloads + gunzips a DB snapshot, verifying it opens cleanly. */
    private function fetchDb(string $key): string
    {
        $gz = $this->storage->get($key) ?? throw new \RuntimeException('Backup object not found: ' . $key);
        $raw = @gzdecode($gz);
        if ($raw === false) {
            throw new \RuntimeException('Corrupt (non-gzip) backup: ' . $key);
        }

        // Integrity-check the candidate BEFORE it touches the live path. A
        // non-SQLite object throws on open/query — treat that as a failed
        // integrity check, never a leaked driver exception.
        $probe = $this->tmpPath('probe', 'db');
        file_put_contents($probe, $raw);
        @chmod($probe, 0600);
        try {
            $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $probe]);
            $check = (string) $conn->fetchOne('PRAGMA quick_check');
            $conn->close();
            if ($check !== 'ok') {
                throw new \RuntimeException('Backup fails integrity check (' . $check . '): ' . $key);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Backup fails integrity check (not a valid database): ' . $key, 0, $e);
        } finally {
            @unlink($probe);
        }

        return $raw;
    }

    /**
     * Swaps $raw in at $livePath after $release closes the handle. Returns
     * the .pre-restore path the displaced DB was moved to (or '' if none).
     *
     * Every filesystem step is checked and staged so a blocked rename (an
     * open handle in another process — schedule:run, a Windows AV scanner)
     * or a short write (ENOSPC) fails LOUDLY instead of reporting a restore
     * that never happened, and the site is never left without a DB.
     *
     * @param \Closure(): void $release
     */
    private function swapDb(string $livePath, string $raw, \Closure $release): string
    {
        $release();

        // Stage the restored bytes as a sibling first — the live DB is only
        // ever replaced by a complete, fully-written file via rename.
        $staged = $livePath . '.restore-staging';
        if (file_put_contents($staged, $raw) !== \strlen($raw)) {
            @unlink($staged);
            throw new \RuntimeException('Failed to stage the restored DB next to ' . $livePath);
        }
        @chmod($staged, 0600);

        $preserved = '';
        if (is_file($livePath)) {
            $preserved = $livePath . '.pre-restore-' . $this->stamp();
            if (!rename($livePath, $preserved)) {
                @unlink($staged);
                throw new \RuntimeException(
                    'Could not move the live DB aside during restore — is it open in another process (worker/scheduler)? ' . $livePath,
                );
            }
        }

        if (!rename($staged, $livePath)) {
            // Put the displaced DB back — never leave the site with NO db.
            if ($preserved !== '') {
                rename($preserved, $livePath);
            }
            @unlink($staged);
            throw new \RuntimeException('Failed to move the restored DB into place: ' . $livePath);
        }
        // The snapshot is a standalone DB (VACUUM INTO leaves no WAL); drop
        // any stale sidecars from the displaced DB so they can't be mixed in.
        @unlink($livePath . '-wal');
        @unlink($livePath . '-shm');

        return $preserved;
    }

    /**
     * Folds the WAL into the DB file BEFORE the swap releases the handle, so
     * the displaced copy we keep is self-contained.
     *
     * Without this the `.pre-restore-{stamp}` file is a HUSK. The rename
     * moves site.db and NOT site.db-wal — the sidecar keeps the name the
     * connection opened — so swapDb's `@unlink($livePath . '-wal')`, which is
     * correct and necessary for the DB being swapped IN, destroys the
     * displaced DB's un-checkpointed tail. With WAL + synchronous=NORMAL that
     * tail is every recent write; verified in a Linux container, where a
     * fresh reader of the preserved file got a disk I/O error while the old
     * handle lived and `no such table` once it closed. The whole point of
     * keeping the file is that a restore is undoable.
     *
     * ONLY on a handle this process already holds, which both call sites
     * check. If nothing of ours is open there is no un-checkpointed WAL that
     * is ours to fold — and forcing one open would CREATE the very file the
     * restore is replacing, which breaks restoring a site whose site.db was
     * DELETED (the M10 criterion, and how this regressed once).
     *
     * SQLite folds the WAL itself when the LAST connection closes, so the
     * husk only ever appeared while another process held a handle — the same
     * precondition as the stale read above. This removes swapDb's dependency
     * on being that last connection, which a `--sync` CLI restore run while
     * the workers are up is not.
     *
     * Best effort by design: a failure here must never abort a restore. The
     * restore IS the recovery action, and all an unfoldable WAL costs is the
     * displaced copy's tail — which is what we were trying to save, not what
     * we were asked to do.
     */
    private function foldWal(Connection $db): void
    {
        try {
            $this->databases->checkpoint($db);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not checkpoint before the restore swap', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $scope, ?string $siteHandle, string $kind, string $key, string $bytes, string $source): array
    {
        $db = $this->databases->engine();
        $db->insert('backups', [
            'scope' => $scope,
            'site_handle' => $siteHandle,
            'kind' => $kind,
            'storage_key' => $key,
            'size_bytes' => \strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'trigger_source' => $source,
            'status' => 'done',
            'created_at' => $this->clock->iso(),
        ]);

        return [
            'id' => (int) $db->lastInsertId(),
            'scope' => $scope,
            'site_handle' => $siteHandle,
            'kind' => $kind,
            'storage_key' => $key,
            'size_bytes' => \strlen($bytes),
        ];
    }

    private function latestKey(string $scope, ?string $siteHandle, string $kind): ?string
    {
        $key = $this->databases->engine()->fetchOne(
            "SELECT storage_key FROM backups
             WHERE scope = ? AND kind = ? AND site_handle IS ? AND status = 'done'
             ORDER BY id DESC LIMIT 1",
            [$scope, $kind, $siteHandle],
        );

        return $key !== false ? (string) $key : null;
    }

    /**
     * @return array<string, string> relative (posix) path => absolute path
     */
    private function filesUnder(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** Pins an explicitly-supplied restore key to the scope it may name. */
    private function assertKeyScope(string $key, string $prefix): void
    {
        if (!str_starts_with($key, $prefix)) {
            throw new \InvalidArgumentException(sprintf(
                'Backup key "%s" does not belong to this restore target (expected prefix "%s").',
                $key,
                $prefix,
            ));
        }
    }

    private function safeRelative(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Unsafe manifest path: ' . $path);
        }

        return $path;
    }

    /** Sortable, collision-resistant object-key stamp. */
    private function stamp(): string
    {
        return $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    private function tmpPath(string $prefix, string $ext): string
    {
        // 0700: these temps hold full plaintext DB copies — owner-only.
        $dir = $this->databases->dataPath() . '/backups/tmp';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create backup tmp dir: ' . $dir);
        }

        return $dir . '/' . $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}
