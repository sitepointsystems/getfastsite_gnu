<?php

declare(strict_types=1);

namespace Fastsite\Media\Service;

use Fastsite\Content\Service\Slugger;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Media intake + the sharp variant pipeline. Images are re-encoded (EXIF and
 * embedded payloads stripped) into AVIF+WebP responsive variants plus an
 * original-format fallback and an OG crop — the exact variant contract the
 * PageAudit later asserts.
 */
final class MediaService
{
    private const RESPONSIVE_WIDTHS = [480, 768, 1024, 1440, 1920];

    private const IMAGE_MAX = 15_728_640;   // 15 MB
    private const VIDEO_MAX = 524_288_000;  // 500 MB
    private const FILE_MAX = 104_857_600;   // 100 MB generic-file cap

    /**
     * Image extensions drive the sharp variant pipeline; their content is
     * verified against these MIME types (the bytes must really be that image).
     */
    private const IMAGE_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'gif' => ['image/gif'],
    ];

    /** Video extensions (stored as-is, no variants) — MIME-verified. */
    private const VIDEO_MIME = [
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
    ];

    /**
     * Everything that is not a known image/video is a `file`: the files
     * library accepts ANY type (calculators, widgets, docs, fonts, archives…).
     * Files are stored as-is, emitted to /files/{id}/ at build, and embedded
     * with {{ filename }} (html/js inline & run; other types → download link).
     * No MIME allowlist — the library is owner-curated (trusted), and the
     * served Content-Type comes from the extension at the CDN with nosniff.
     */
    private function classify(string $ext): array
    {
        if (isset(self::IMAGE_MIME[$ext])) {
            return [self::IMAGE_MIME[$ext], 'image', self::IMAGE_MAX];
        }
        if (isset(self::VIDEO_MIME[$ext])) {
            return [self::VIDEO_MIME[$ext], 'video', self::VIDEO_MAX];
        }

        return [null, 'file', self::FILE_MAX]; // null mimeAllow = accept any content
    }

    public function __construct(
        private readonly MediaRepository $repository,
        private readonly SiteDatabaseManager $databases,
        private readonly SafeUrlFetcher $fetcher,
        private readonly LoggerInterface $logger,
        private readonly \Fastsite\Media\ImageOptimizer $optimizer,
        private readonly string $appRoot,
        // Per-site ShortPixel key support. Nullable/defaulted so test fixtures
        // that build MediaService positionally keep working.
        private readonly ?\Fastsite\Shared\Crypto\TokenCipher $cipher = null,
        private readonly int $shortpixelLossy = 1,
    ) {
    }

    /**
     * The image optimizer for a site: its own encrypted ShortPixel key when set
     * (their credits, not the platform's), else the global default optimizer.
     */
    private function optimizerFor(Site $site): \Fastsite\Media\ImageOptimizer
    {
        $enc = $site->shortpixelKeyEnc;
        if ($enc !== null && $enc !== '' && $this->cipher !== null) {
            $key = $this->cipher->decrypt($enc);
            if (\is_string($key) && $key !== '') {
                return new \Fastsite\Media\ShortPixelOptimizer($key, $this->logger, $this->shortpixelLossy);
            }
            $this->logger->warning('Per-site ShortPixel key could not be decrypted — using the default optimizer', ['site' => $site->handle]);
        }

        return $this->optimizer;
    }

    /** Largest per-type cap — the ceiling before any type is known. */
    public static function maxUploadBytes(): int
    {
        return max(self::IMAGE_MAX, self::VIDEO_MAX, self::FILE_MAX);
    }

    /**
     * @param list<string>|null $kinds restrict to these kinds (image/video/file); null = all
     * @return array{items: list<\Fastsite\Media\Dto\Media>, total: int}
     */
    public function list(string $siteHandle, int $page, int $perPage, ?array $kinds = null): array
    {
        return $this->repository->list($siteHandle, $page, $perPage, $kinds);
    }

    /**
     * @param list<string>|null $kinds
     * @return array{items: list<Media>, total: int}
     */
    public function listTrashed(string $siteHandle, int $page, int $perPage, ?array $kinds = null): array
    {
        return $this->repository->listTrashed($siteHandle, $page, $perPage, $kinds);
    }

    /**
     * Move an item to the trash: hidden from the library + pickers, restorable.
     * Media already referenced by content keeps rendering until it's purged.
     */
    public function softDelete(Site $site, int $id): Media
    {
        if ($this->repository->findById($site->handle, $id) === null) {
            throw new \RuntimeException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }
        $this->repository->softDelete($site->handle, $id);
        $m = $this->repository->findAnyById($site->handle, $id);
        \assert($m !== null);

        return $m;
    }

    public function restore(Site $site, int $id): Media
    {
        $existing = $this->repository->findAnyById($site->handle, $id);
        if ($existing === null || $existing->deletedAt === null) {
            throw new \RuntimeException(sprintf('Trashed media #%d not found on site "%s".', $id, $site->handle));
        }
        $this->repository->restore($site->handle, $id);
        $m = $this->repository->findById($site->handle, $id);
        \assert($m !== null);

        return $m;
    }

    /** Permanently delete an item: its DB row, variants, and files on disk. */
    public function purge(Site $site, int $id): void
    {
        $existing = $this->repository->findAnyById($site->handle, $id);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }
        $this->removeFiles($site, $existing);
        $this->repository->delete($site->handle, $id); // media_variants cascade
    }

    /** Edit an item's alt text / decorative flag (validated for images). */
    public function updateMeta(Site $site, int $id, ?string $alt, ?bool $decorative): Media
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }

        $fields = [];
        if ($alt !== null) {
            $fields['alt'] = mb_substr(trim($alt), 0, 1000);
        }
        if ($decorative !== null) {
            $fields['decorative'] = $decorative ? 1 : 0;
        }

        $effectiveAlt = $fields['alt'] ?? $existing->alt;
        $effectiveDecorative = $decorative ?? $existing->decorative;
        if ($existing->kind === 'image' && $effectiveAlt === '' && !$effectiveDecorative) {
            throw new \InvalidArgumentException('alt text is required for images (or mark it decorative).');
        }

        if ($fields !== []) {
            $this->repository->update($site->handle, $id, $fields);
        }
        $m = $this->repository->findById($site->handle, $id);
        \assert($m !== null);

        return $m;
    }

    /** Remove an item's original file, its variant dir, and any stray variant files. */
    private function removeFiles(Site $site, Media $media): void
    {
        // disk_path is stored RELATIVE to the site dir — resolve against it
        // (an absolute path from a legacy row still works via the ltrim/join).
        $sitePath = $this->databases->sitePath($site->handle);
        $abs = static fn (string $p): string => str_starts_with($p, $sitePath) ? $p : $sitePath . '/' . ltrim($p, '/');

        if ($media->diskPath !== '' && is_file($abs($media->diskPath))) {
            @unlink($abs($media->diskPath));
        }
        foreach ($media->variants as $variant) {
            if ($variant->diskPath !== '' && is_file($abs($variant->diskPath))) {
                @unlink($abs($variant->diskPath));
            }
        }
        $this->removeDir($sitePath . '/media/variants/' . $media->id);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Ingests raw bytes already in memory (multipart upload or URL import).
     * Runs the variant pipeline synchronously for images so the response
     * carries a ready asset.
     */
    public function ingest(Site $site, string $bytes, string $originalName, string $alt, bool $decorative = false, ?string $sourceUrl = null): Media
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'bin'; // extensionless upload still lands in the files library
        }
        // Known image/video extensions drive their pipelines and are
        // MIME-verified; everything else is a `file` (any type, no MIME check).
        [$mimeAllow, $kind, $maxBytes] = $this->classify($ext);

        if (\strlen($bytes) === 0) {
            throw new \InvalidArgumentException('Empty file.');
        }
        if (\strlen($bytes) > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('File exceeds the %d MB limit for .%s', intdiv($maxBytes, 1048576), $ext));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->buffer($bytes);
        if ($mimeAllow !== null && !\in_array($detected, $mimeAllow, true)) {
            throw new \InvalidArgumentException(sprintf('Content (%s) does not match extension .%s', $detected, $ext));
        }

        if ($kind === 'image' && $alt === '' && !$decorative) {
            throw new \InvalidArgumentException('alt text is required for images (or set decorative=true).');
        }

        $sha = hash('sha256', $bytes);
        $existing = $this->repository->findBySha($site->handle, $sha);
        if ($existing !== null) {
            // Re-uploading identical bytes that are in the trash restores them
            // (and avoids the sha256 UNIQUE collision a fresh insert would hit).
            if ($existing->deletedAt !== null) {
                $this->repository->restore($site->handle, $existing->id);
            }

            return $this->repository->findById($site->handle, $existing->id) ?? $existing;
        }

        // Optimize images on landing (ShortPixel) BEFORE sharp reads the
        // original for variants — every AVIF/WebP derives from the best-
        // compressed source. Dedupe (sha256 above) stays on the incoming
        // bytes; the stored file + `bytes` reflect the optimized original.
        // optimize() fails safe (returns the input on any error); we ALSO
        // re-verify the returned bytes are still an allowed image type, so a
        // bad optimizer response can never be stored as the "original".
        if ($kind === 'image') {
            $optimized = $this->optimizerFor($site)->optimize($bytes, $originalName);
            if ($optimized !== $bytes) {
                $optMime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($optimized);
                if (\in_array($optMime, $mimeAllow, true)) {
                    $bytes = $optimized;
                } else {
                    $this->logger->warning('Image optimizer returned an unexpected type — keeping the original', ['site' => $site->handle, 'mime' => $optMime]);
                }
            }
        }

        $slug = Slugger::slugify(pathinfo($originalName, PATHINFO_FILENAME));
        $canonicalExt = $ext === 'jpeg' ? 'jpg' : $ext;
        $sitePath = $this->databases->sitePath($site->handle);
        $subdir = date('Y') . '/' . date('m');
        $originalsDir = $sitePath . '/media/originals/' . $subdir;
        if (!is_dir($originalsDir) && !mkdir($originalsDir, 0770, true) && !is_dir($originalsDir)) {
            throw new \RuntimeException('Could not create media directory.');
        }

        $mediaId = $this->repository->insert($site->handle, [
            'kind' => $kind,
            'original_name' => $originalName,
            'slug' => $slug,
            'ext' => $canonicalExt,
            'mime' => $detected,
            'sha256' => $sha,
            'bytes' => \strlen($bytes),
            'alt' => $alt,
            'decorative' => $decorative ? 1 : 0,
            'storage' => 'git',
            'disk_path' => 'placeholder',
            'source_url' => $sourceUrl,
            'status' => 'pending',
        ]);

        $originalPath = $originalsDir . '/' . $mediaId . '-' . $slug . '.' . $canonicalExt;
        file_put_contents($originalPath, $bytes);
        $relativeOriginal = 'media/originals/' . $subdir . '/' . $mediaId . '-' . $slug . '.' . $canonicalExt;
        $this->repository->update($site->handle, $mediaId, ['disk_path' => $relativeOriginal]);

        if ($kind === 'image') {
            $this->processImage($site, $mediaId, $slug, $originalPath, $sitePath);
        } else {
            $this->repository->update($site->handle, $mediaId, ['status' => 'ready']);
        }

        $media = $this->repository->findById($site->handle, $mediaId);
        \assert($media !== null);

        return $media;
    }

    /** Image kind's byte cap — the ceiling for URL image adoption. */
    public const IMAGE_MAX_BYTES = 15_728_640;

    public function importFromUrl(Site $site, string $url, string $alt, bool $decorative = false, ?int $maxBytes = null): Media
    {
        // Default to the IMAGE cap (the only kind adopted from URLs today) so
        // a hostile endpoint can't stream ~500 MB into memory before the
        // per-type check runs. Callers importing other kinds pass a cap.
        $result = $this->fetcher->fetch($url, $maxBytes ?? self::IMAGE_MAX_BYTES);
        $name = basename((string) parse_url($result['final_url'], PHP_URL_PATH)) ?: 'import';
        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.' . $this->extForMime($result['mime']);
        }

        return $this->ingest($site, $result['body'], $name, $alt, $decorative, $result['final_url']);
    }

    private function processImage(Site $site, int $mediaId, string $slug, string $originalPath, string $sitePath): void
    {
        $variantsDir = $sitePath . '/media/variants/' . $mediaId;
        $job = [
            'original' => $originalPath,
            'kind' => 'image',
            'outDir' => $variantsDir,
            'slug' => $slug,
            'widths' => self::RESPONSIVE_WIDTHS,
            'maxWidth' => $site->imageMaxWidth,
            'og' => true,
        ];

        $jobFile = tempnam(sys_get_temp_dir(), 'fsmedia');
        file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));

        try {
            $process = new Process(['node', $this->appRoot . '/bin/media-process.mjs', $jobFile], $this->appRoot, null, null, 120);
            $process->run();
            $result = json_decode($process->getOutput(), true);

            if (!\is_array($result) || ($result['ok'] ?? false) !== true) {
                $error = \is_array($result) ? (string) ($result['error'] ?? 'unknown') : trim($process->getErrorOutput());
                throw new \RuntimeException('Image processing failed: ' . $error);
            }

            $variants = [];
            foreach ($result['variants'] as $v) {
                $variants[] = [
                    'purpose' => $v['purpose'],
                    'format' => $v['format'],
                    'width' => (int) $v['width'],
                    'height' => (int) $v['height'],
                    'disk_path' => 'media/variants/' . $mediaId . '/' . basename((string) $v['path']),
                    'bytes' => (int) $v['bytes'],
                ];
            }
            $this->repository->replaceVariants($site->handle, $mediaId, $variants);
            $this->repository->update($site->handle, $mediaId, [
                'width' => (int) $result['width'],
                'height' => (int) $result['height'],
                'status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            // Roll the ingest back so a failed attempt does NOT claim the
            // sha256 dedupe slot (an identical re-upload must reprocess) and
            // does not leave an orphaned original on disk.
            $this->repository->delete($site->handle, $mediaId);
            @unlink($originalPath);
            $this->cleanupVariants($sitePath, $mediaId);
            $this->logger->error('Media processing failed', ['site' => $site->handle, 'media' => $mediaId, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            @unlink($jobFile);
        }
    }

    private function cleanupVariants(string $sitePath, int $mediaId): void
    {
        $dir = $sitePath . '/media/variants/' . $mediaId;
        if (!is_dir($dir)) {
            return;
        }
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    private function extForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
