<?php

declare(strict_types=1);

namespace Fastsite\Identity\Service;

use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * User profile images for the engine UI (the sidebar avatar). Stored as
 * plain files at {DATA_PATH}/engine/avatars/{userId}.{ext} — file existence
 * IS the state, no users column. Uploads are mime-sniffed (never trusting
 * the client type), capped, and replace any previous extension.
 */
final class AvatarService
{
    private const MAX_BYTES = 2_097_152; // 2 MB
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly SiteDatabaseManager $databases)
    {
    }

    /** Absolute path of the user's avatar file, or null when none is set. */
    public function pathFor(int $userId): ?string
    {
        foreach (self::TYPES as $ext) {
            $path = $this->dir() . '/' . $userId . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Public URL for the sidebar (mtime-busted), or null when none is set. */
    public function urlFor(int $userId): ?string
    {
        $path = $this->pathFor($userId);

        return $path === null ? null : '/account/avatar?v=' . (int) filemtime($path);
    }

    public function save(int $userId, string $bytes): void
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('The uploaded file is empty.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Profile image exceeds the 2 MB limit.');
        }
        // Sniff the real type — the client-declared mime is not trusted.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = self::TYPES[$mime] ?? null;
        if ($ext === null) {
            throw new \InvalidArgumentException('Profile image must be a JPEG, PNG or WebP.');
        }

        $dir = $this->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $this->remove($userId); // drop any previous extension first
        if (file_put_contents($dir . '/' . $userId . '.' . $ext, $bytes) === false) {
            throw new \RuntimeException('Could not store the profile image.');
        }
    }

    public function remove(int $userId): void
    {
        foreach (self::TYPES as $ext) {
            $path = $this->dir() . '/' . $userId . '.' . $ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Content type for serving, derived from the stored extension. */
    public static function mimeFor(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/webp',
        };
    }

    private function dir(): string
    {
        return $this->databases->dataPath() . '/engine/avatars';
    }
}
