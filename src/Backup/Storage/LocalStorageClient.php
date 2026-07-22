<?php

declare(strict_types=1);

namespace Fastsite\Backup\Storage;

/**
 * Filesystem-backed storage under {DATA_PATH}/backups-store. Used when R2 is
 * unconfigured (single-server still gets local snapshots) and in tests. Keys
 * are validated to stay under the root.
 */
final class LocalStorageClient implements StorageClient
{
    public function __construct(private readonly string $root)
    {
    }

    public function put(string $key, string $bytes): void
    {
        $path = $this->path($key);
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create backup directory: ' . $dir);
        }
        if (file_put_contents($path, $bytes) !== \strlen($bytes)) {
            throw new \RuntimeException('Short write on backup: ' . $key);
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function list(string $prefix): array
    {
        $base = $this->path($prefix);
        $dir = is_dir($base) ? $base : \dirname($base);
        if (!is_dir($dir)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            $key = str_replace('\\', '/', substr($file->getPathname(), \strlen($this->root) + 1));
            if (str_starts_with($key, $prefix)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'local (' . $this->root . ')';
    }

    private function path(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if (str_contains($key, '..') || str_contains($key, "\0")) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }

        return $this->root . '/' . $key;
    }
}
