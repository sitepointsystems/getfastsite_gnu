<?php

declare(strict_types=1);

namespace Fastsite\Shell\Service;

use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * The shell file API — how AI agents design a site. Every write is
 * path-contained (realpath under shell/), extension-allowlisted, syncs the
 * shell_files manifest, bumps sites.theme_version, and enqueues a coalesced
 * full rebuild (dedupe build:{site}) so a burst of file writes becomes ONE
 * build.
 */
final class ShellService
{
    private const ALLOWED_EXT = ['twig', 'css', 'js', 'json', 'woff2', 'woff', 'ico', 'svg', 'png', 'jpg', 'webp', 'avif', 'txt', 'xml'];
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly SiteRepository $sites,
        private readonly PublishTrigger $publish,
        private readonly BuildService $build,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{path: string, sha256: string, size_bytes: int, updated_at: string}>
     */
    public function listFiles(Site $site): array
    {
        // Rebuild the manifest from disk on read so it's always accurate
        // even if files were placed out of band (e.g. initial scaffold).
        $this->syncManifest($site);

        return array_map(
            static fn (array $r): array => [
                'path' => (string) $r['path'],
                'sha256' => (string) $r['sha256'],
                'size_bytes' => (int) $r['size_bytes'],
                'updated_at' => (string) $r['updated_at'],
            ],
            $this->databases->site($site->handle)->fetchAllAssociative('SELECT * FROM shell_files ORDER BY path'),
        );
    }

    public function readFile(Site $site, string $relativePath): string
    {
        $full = $this->resolve($site, $relativePath);
        if (!is_file($full)) {
            throw new ShellNotFoundException('No such shell file: ' . $relativePath);
        }

        return (string) file_get_contents($full);
    }

    /**
     * Writes (creates or overwrites) a shell file and triggers a rebuild.
     */
    public function writeFile(Site $site, string $relativePath, string $content, string $actor): void
    {
        if (\strlen($content) > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Shell file exceeds the 5 MB limit.');
        }
        $full = $this->resolve($site, $relativePath);

        $dir = \dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create shell directory.');
        }

        // Write, then validate the shell by dry-rendering the representative
        // page set. A template that throws (sandbox violation, Twig error) or
        // FAILs the PageAudit is rejected HERE — reverting the file — so a
        // poisoned shell never reaches the async build and can't wedge the
        // site. The dangerous render happens in this bounded request, not the
        // worker.
        $previous = is_file($full) ? (string) file_get_contents($full) : null;
        if (file_put_contents($full, $content) !== \strlen($content)) {
            throw new \RuntimeException('Short write on shell file: ' . $relativePath);
        }

        $failures = $this->build->validateShell($site);
        if ($failures !== []) {
            // Roll back to the previous content (or remove a new file).
            if ($previous !== null) {
                file_put_contents($full, $previous);
            } else {
                @unlink($full);
            }
            throw new \InvalidArgumentException('Shell change rejected — it would break the build: ' . implode('; ', $failures));
        }

        $this->upsertManifest($site, $relativePath, $content);
        $this->onShellChanged($site, $actor, 'write ' . $relativePath);
    }

    public function deleteFile(Site $site, string $relativePath, string $actor): void
    {
        $full = $this->resolve($site, $relativePath);
        if (!is_file($full)) {
            throw new ShellNotFoundException('No such shell file: ' . $relativePath);
        }
        unlink($full);
        $this->databases->site($site->handle)->executeStatement('DELETE FROM shell_files WHERE path = ?', [$relativePath]);
        $this->onShellChanged($site, $actor, 'delete ' . $relativePath);
    }

    /**
     * Snapshots the current shell into a zip revision (local now; synced to
     * R2 in M10). Returns the new revision number.
     */
    public function snapshot(Site $site, string $note, string $actor): int
    {
        $shellDir = $this->shellDir($site);
        $revDir = $this->databases->sitePath($site->handle) . '/shell-revisions';
        if (!is_dir($revDir) && !mkdir($revDir, 0770, true) && !is_dir($revDir)) {
            throw new \RuntimeException('Could not create shell-revisions directory.');
        }

        $db = $this->databases->site($site->handle);
        $revNo = (int) $db->fetchOne('SELECT COALESCE(MAX(rev_no), 0) + 1 FROM shell_revisions');
        $archivePath = $revDir . '/rev-' . $revNo . '.zip';

        $this->zipDir($shellDir, $archivePath);

        $db->insert('shell_revisions', [
            'rev_no' => $revNo,
            'archive_path' => 'shell-revisions/rev-' . $revNo . '.zip',
            'note' => $note,
            'created_by' => $actor,
            'created_at' => $this->clock->iso(),
        ]);

        return $revNo;
    }

    /**
     * Deletes one snapshot: unlinks the zip (the part that costs disk) and
     * tombstones the row.
     *
     * The row is kept deliberately. snapshot() numbers with MAX(rev_no)+1, so
     * hard-deleting the newest revision would reissue its number and make
     * "restore rev 3" mean a different tree than it did before — including for
     * the MCP shell_restore tool, whose only argument is that number. A
     * tombstone holds the number permanently (rev_no is UNIQUE).
     *
     * The archive path is rebuilt from the integer rev_no rather than trusting
     * the stored string, so a tampered row can never point the unlink outside
     * the site's own shell-revisions directory.
     */
    public function deleteRevision(Site $site, int $revNo, string $actor): void
    {
        $db = $this->databases->site($site->handle);
        $row = $db->fetchAssociative(
            'SELECT rev_no FROM shell_revisions WHERE rev_no = ? AND deleted_at IS NULL',
            [$revNo],
        );
        if ($row === false) {
            throw new ShellNotFoundException('No such shell revision: ' . $revNo);
        }

        $archive = $this->databases->sitePath($site->handle) . '/shell-revisions/rev-' . $revNo . '.zip';
        // Tombstone even when the zip is already gone — otherwise a revision
        // whose file was lost could never be cleared from the list.
        if (is_file($archive) && !@unlink($archive)) {
            throw new \RuntimeException('Could not delete the snapshot archive for revision ' . $revNo . '.');
        }

        $db->executeStatement(
            'UPDATE shell_revisions SET deleted_at = ? WHERE rev_no = ?',
            [$this->clock->iso(), $revNo],
        );
        $this->logger->info('Shell revision deleted', ['site' => $site->handle, 'rev' => $revNo, 'actor' => $actor]);
    }

    /**
     * Restores a snapshot over the shell tree, then rebuilds.
     */
    public function restore(Site $site, int $revNo, string $actor): void
    {
        $db = $this->databases->site($site->handle);
        // A tombstoned revision keeps its number but its archive is gone, so it
        // must read as absent rather than as "archive missing on disk".
        $row = $db->fetchAssociative(
            'SELECT archive_path FROM shell_revisions WHERE rev_no = ? AND deleted_at IS NULL',
            [$revNo],
        );
        if ($row === false) {
            throw new ShellNotFoundException('No such shell revision: ' . $revNo);
        }

        $archive = $this->databases->sitePath($site->handle) . '/' . $row['archive_path'];
        if (!is_file($archive)) {
            throw new \RuntimeException('Snapshot archive is missing on disk.');
        }

        $shellDir = $this->shellDir($site);
        // Auto-snapshot the current state first so a restore is itself undoable.
        $this->snapshot($site, 'pre-restore of rev ' . $revNo, $actor);

        // Extract to a temp dir and swap atomically — a mid-unzip failure
        // must never leave a half-wiped live shell.
        $staging = $shellDir . '.restoring';
        $this->clearDir($staging);
        if (is_dir($staging)) {
            rmdir($staging);
        }
        if (!mkdir($staging, 0770, true) && !is_dir($staging)) {
            throw new \RuntimeException('Could not create restore staging directory.');
        }

        try {
            $this->unzip($archive, $staging);
        } catch (\Throwable $e) {
            $this->clearDir($staging);
            @rmdir($staging);
            throw $e; // live shell untouched
        }

        $backup = $shellDir . '.old';
        $this->clearDir($backup);
        @rmdir($backup);
        rename($shellDir, $backup);
        rename($staging, $shellDir);
        $this->clearDir($backup);
        @rmdir($backup);

        $this->syncManifest($site);
        $this->onShellChanged($site, $actor, 'restore rev ' . $revNo);
    }

    private function onShellChanged(Site $site, string $actor, string $reason): void
    {
        $this->sites->update($site->id, ['theme_version' => $site->themeVersion + 1]);

        // A shell change affects every page → rebuild, coalesced so an
        // agent's burst of file writes triggers ONE build. On a multilingual
        // site this also refreshes shell UI-string translations first.
        $this->publish->trigger($site, 'publish: shell — ' . $reason, 'shell');

        $this->logger->info('Shell changed', ['site' => $site->handle, 'actor' => $actor, 'reason' => $reason]);
    }

    /**
     * Resolves a caller path to an absolute path guaranteed to sit under
     * shell/. Rejects traversal, dotfiles, and disallowed extensions.
     */
    private function resolve(Site $site, string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('Invalid shell path.');
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                throw new \InvalidArgumentException('Shell paths may not contain empty or dot segments.');
            }
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!\in_array($ext, self::ALLOWED_EXT, true)) {
            throw new \InvalidArgumentException('Disallowed shell file extension: .' . $ext);
        }

        $shellDir = $this->shellDir($site);
        $full = $shellDir . '/' . $relativePath;

        // Containment: the resolved directory must be inside shell/.
        $realDir = realpath(\dirname($full));
        $realShell = realpath($shellDir);
        if ($realShell === false) {
            throw new \RuntimeException('Shell directory missing.');
        }
        if ($realDir !== false && !str_starts_with($realDir . \DIRECTORY_SEPARATOR, $realShell . \DIRECTORY_SEPARATOR) && $realDir !== $realShell) {
            throw new \InvalidArgumentException('Shell path escapes the shell directory.');
        }

        return $full;
    }

    private function shellDir(Site $site): string
    {
        return $this->databases->sitePath($site->handle) . '/shell';
    }

    private function upsertManifest(Site $site, string $relativePath, string $content): void
    {
        $this->databases->site($site->handle)->executeStatement(
            'INSERT INTO shell_files (path, sha256, size_bytes, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (path) DO UPDATE SET sha256 = excluded.sha256, size_bytes = excluded.size_bytes, updated_at = excluded.updated_at',
            [$relativePath, hash('sha256', $content), \strlen($content), $this->clock->iso()],
        );
    }

    /**
     * Reconciles the manifest with what's actually on disk.
     */
    private function syncManifest(Site $site): void
    {
        $shellDir = $this->shellDir($site);
        if (!is_dir($shellDir)) {
            return;
        }
        $db = $this->databases->site($site->handle);

        $seen = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($shellDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($shellDir) + 1));
            $seen[$rel] = true;
            $content = (string) file_get_contents($file->getPathname());
            $this->upsertManifest($site, $rel, $content);
        }

        // Drop manifest rows for files no longer on disk.
        foreach ($db->fetchFirstColumn('SELECT path FROM shell_files') as $path) {
            if (!isset($seen[(string) $path])) {
                $db->executeStatement('DELETE FROM shell_files WHERE path = ?', [$path]);
            }
        }
    }

    /**
     * Snapshots are zip revisions, so a runtime without ext-zip cannot make or
     * restore one. Without this the dashboard raised a bare "Class ZipArchive
     * not found" Error — which the action handler does not catch, so the user
     * got a 500 instead of a flash message naming the actual problem.
     */
    private function assertZipAvailable(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'Shell snapshots need the PHP zip extension, which is not installed on this server.',
            );
        }
    }

    private function zipDir(string $sourceDir, string $zipPath): void
    {
        $this->assertZipAvailable();
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create snapshot archive.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($sourceDir) + 1));
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();
    }

    private function unzip(string $zipPath, string $targetDir): void
    {
        $this->assertZipAvailable();
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open snapshot archive.');
        }
        // Guard against zip-slip: every entry must stay under targetDir.
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException('Snapshot archive contains an unsafe path.');
            }
        }
        $zip->extractTo($targetDir);
        $zip->close();
    }

    private function clearDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }
}
