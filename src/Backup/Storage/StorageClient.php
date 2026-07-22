<?php

declare(strict_types=1);

namespace Fastsite\Backup\Storage;

/**
 * Object storage for backups. Two implementations: S3Client (Cloudflare R2)
 * and LocalStorageClient (a local directory — used when R2 is unconfigured
 * and in tests). Keys are POSIX-style paths, e.g. "site/demo/db/2026-...".
 */
interface StorageClient
{
    public function put(string $key, string $bytes): void;

    public function get(string $key): ?string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /**
     * @return list<string> keys under the prefix
     */
    public function list(string $prefix): array;

    public function isConfigured(): bool;

    /** Human label for doctor output. */
    public function describe(): string;
}
