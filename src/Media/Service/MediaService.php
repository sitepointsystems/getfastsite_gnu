<?php

declare(strict_types=1);

namespace Fastsite\Media\Service;

use Fastsite\Content\Service\Slugger;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\MediaNotFoundException;
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
        // Automatic alt text (vision AI). Nullable/defaulted for the same
        // fixture reason — without it, missing alt falls back to the filename.
        private readonly ?\Fastsite\Ai\AiClientFactory $ai = null,
        private readonly ?\Fastsite\Shared\Clock\Clock $clock = null,
    ) {
    }

    private function nowIso(): string
    {
        return $this->clock?->iso() ?? gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * The image optimizer for a site: its own encrypted ShortPixel key when set
     * (their credits, not the platform's), else the global default optimizer.
     */
    /** ShortPixel's lossy parameter for the site's compression choice. */
    private function lossyLevelFor(Site $site): int
    {
        return match ($site->imageCompression) {
            'lossless' => 0,
            'glossy' => 2,
            default => 1, // lossy
        };
    }

    /**
     * sharp encoder qualities per compression level — the variants are what a
     * page actually serves, so this knob (not ShortPixel) decides final sizes.
     *
     * @return array{avif: int, webp: int, jpeg: int}
     */
    private function variantQualityFor(Site $site): array
    {
        return match ($site->imageCompression) {
            'lossless' => ['avif' => 75, 'webp' => 92, 'jpeg' => 95],
            'glossy' => ['avif' => 60, 'webp' => 85, 'jpeg' => 88],
            default => ['avif' => 50, 'webp' => 78, 'jpeg' => 82], // lossy
        };
    }

    private function optimizerFor(Site $site): \Fastsite\Media\ImageOptimizer
    {
        $lossy = $this->lossyLevelFor($site);
        $enc = $site->shortpixelKeyEnc;
        if ($enc !== null && $enc !== '' && $this->cipher !== null) {
            $key = $this->cipher->decrypt($enc);
            if (\is_string($key) && $key !== '') {
                return new \Fastsite\Media\ShortPixelOptimizer($key, $this->logger, $lossy);
            }
            $this->logger->warning('Per-site ShortPixel key could not be decrypted — using the default optimizer', ['site' => $site->handle]);
        }

        // The global optimizer instance carries the env default level — apply
        // the site's choice to it too.
        if ($this->optimizer instanceof \Fastsite\Media\ShortPixelOptimizer) {
            return $this->optimizer->withLossy($lossy);
        }

        return $this->optimizer;
    }

    /** Whether an image optimizer (ShortPixel key) is active for this site. */
    public function optimizerConfiguredFor(Site $site): bool
    {
        return $this->optimizerFor($site)->isConfigured();
    }

    /** Ready images that never went through the external optimizer. */
    public function countUnoptimized(string $siteHandle): int
    {
        return $this->repository->countUnoptimizedImages($siteHandle);
    }

    /** @return list<int> */
    public function unoptimizedImageIds(string $siteHandle): array
    {
        return $this->repository->unoptimizedImageIds($siteHandle);
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
     * One item WITH its renditions — `findById` deliberately doesn't load them
     * (most callers only need the row), but anything that has to name the
     * file's public URL does: the URL carries the variant's width.
     */
    public function findWithVariants(string $siteHandle, int $id): ?Media
    {
        return $this->repository->loadManyWithVariants($siteHandle, [$id])[$id] ?? null;
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
            // An extensionless name still lands in the files library by
            // default, and that default is right: a Makefile, a LICENSE or a
            // README is not improved by being renamed to Makefile.bin.
            //
            // But if the bytes ARE a known image or video, say so. A caller
            // that streamed an image with no filename — a multipart POST from
            // a client that sends none, an upload ticket redeemed by someone
            // who only had bytes — otherwise got a silent download link
            // instead of an <img>, which is the same defect as a mismatched
            // extension below: we identified the content and mislabelled it
            // anyway. Only the media maps qualify, so anything we cannot name
            // keeps the files-library behaviour rather than being guessed at.
            $sniffed = $this->extForMime((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
            if (isset(self::IMAGE_MIME[$sniffed]) || isset(self::VIDEO_MIME[$sniffed])) {
                $base = pathinfo($originalName, PATHINFO_FILENAME);
                $originalName = ($base === '' ? 'upload' : $base) . '.' . $sniffed;
                $ext = $sniffed;
            } else {
                $ext = 'bin';
            }
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
            // We have ALREADY identified the bytes — we sniffed them to write
            // the error message. Refusing a file we can name is no safer than
            // storing it under the right extension, and it is exactly how an
            // agent that guessed "photo.png" for a JPEG loses a good upload:
            // a caller holding bytes often has no reliable filename, and the
            // one it invents is the only thing wrong with the request.
            //
            // So reconcile the extension to the bytes — but ONLY WITHIN THE
            // SAME KIND, and that restriction is load-bearing. `.html` files
            // are inlined VERBATIM at build, so "correcting" a text/html
            // payload called photo.png into photo.html would turn a rejected
            // upload into stored XSS. Cross-kind stays refused too: silently
            // turning a claimed video into an image would surprise the caller
            // about which pipeline ran and what the embed renders as.
            $sameKind = $kind === 'image' ? self::IMAGE_MIME : self::VIDEO_MIME;
            $corrected = $this->extForMime($detected);
            if (!isset($sameKind[$corrected])) {
                throw new \InvalidArgumentException(sprintf('Content (%s) does not match extension .%s', $detected, $ext));
            }

            $this->logger->warning('Upload extension did not match its bytes — stored under the sniffed type', [
                'site' => $site->handle,
                'given' => $ext,
                'detected' => $detected,
                'stored' => $corrected,
            ]);
            $base = pathinfo($originalName, PATHINFO_FILENAME);
            $originalName = ($base === '' ? 'upload' : $base) . '.' . $corrected;
            $ext = $corrected;
            // The allowlist has to follow the corrected extension: it is read
            // again below to re-verify the optimizer's output, and a stale one
            // would reject every ShortPixel result as an unexpected type. The
            // size cap does not move, because it is per KIND and the kind is
            // the thing we just held fixed.
            $mimeAllow = $sameKind[$corrected];
        }

        // Missing alt on an image is no longer an error: it is generated
        // automatically after the variants exist (vision AI when the site has
        // an AI credential, else derived from the filename). Explicit alt and
        // decorative=true are always respected.
        $needsAlt = $kind === 'image' && trim($alt) === '' && !$decorative;

        $sha = hash('sha256', $bytes);
        $existing = $this->repository->findBySha($site->handle, $sha);
        if ($existing !== null) {
            // Re-uploading identical bytes that are in the trash restores them
            // (and avoids the sha256 UNIQUE collision a fresh insert would hit).
            //
            // But it has to come back as a FRESH item, not as its former self.
            // Someone who deleted an image and uploaded the same file again is
            // adding something new as far as they are concerned, and silently
            // handing back the old row meant they inherited its alt text and —
            // far worse — its crop, so the "new" upload appeared already
            // reframed by a decision they had thrown away.
            //
            // The id and slug still stand: they are what every embed resolves
            // on, and minting a new row would collide on sha256 anyway.
            if ($existing->deletedAt !== null) {
                $this->repository->restore($site->handle, $existing->id);
                if (trim($alt) !== '') {
                    $this->repository->update($site->handle, $existing->id, ['alt' => mb_substr(trim($alt), 0, 1000)]);
                }
                if ($existing->sharpTransform() !== null) {
                    // Clears the stored crop/resize AND rebuilds the variants,
                    // which is required: clearing the columns alone would leave
                    // the row describing a framing the files on disk do not have.
                    $this->transform($site, $existing->id, null, null);
                }
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
        $optimizedAt = null;
        if ($kind === 'image') {
            $optimizer = $this->optimizerFor($site);
            $optimized = $optimizer->optimize($bytes, $originalName);
            if ($optimized !== $bytes) {
                $optMime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($optimized);
                if (\in_array($optMime, $mimeAllow, true)) {
                    $bytes = $optimized;
                } else {
                    $this->logger->warning('Image optimizer returned an unexpected type — keeping the original', ['site' => $site->handle, 'mime' => $optMime]);
                }
            }
            // Record a SUCCESSFUL attempt (even "no further gain") so the
            // /media screen never spends credits twice on the same image; a
            // transport failure leaves it unmarked = retryable there.
            if ($optimizer->isConfigured() && !$optimizer->lastAttemptFailed()) {
                $optimizedAt = $this->nowIso();
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
            'optimized_at' => $optimizedAt,
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

        if ($needsAlt) {
            $this->autoAlt($site, $mediaId);
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

    /**
     * Ingests base64-encoded bytes — the binary sibling of importFromUrl, for
     * callers that already HOLD the file and have nowhere to host it (an agent
     * over MCP with a locally generated image). Accepts a bare base64 string or
     * a `data:<mime>;base64,…` URI, tolerating the line breaks JSON/JSON-RPC
     * transports wrap long strings in. Past the decode this is the SAME path as
     * a multipart upload: MIME verification against the extension, sha256
     * dedupe, ShortPixel, the sharp variant pipeline and auto alt.
     *
     * The cap mirrors importFromUrl's — the image ceiling by default (the only
     * kind a base64 payload realistically carries), raised explicitly by
     * callers that mean to accept another kind — and is checked BEFORE the
     * decode so an oversized payload is never materialized.
     */
    public function ingestBase64(Site $site, string $data, string $filename = '', string $alt = '', bool $decorative = false, ?int $maxBytes = null): Media
    {
        [$bytes, $declaredMime] = $this->decodeBase64($data, $maxBytes);

        return $this->ingest($site, $bytes, $this->resolveFilename($filename, $declaredMime, $bytes), $alt, $decorative);
    }

    /**
     * Decodes a bare base64 string or a `data:<mime>;base64,…` URI to raw
     * bytes + the declared MIME (when the URI carried one). Shared by the
     * base64 ingest and the base64 replace so both enforce the same ceiling.
     *
     * @return array{0: string, 1: string|null}
     */
    private function decodeBase64(string $data, ?int $maxBytes = null): array
    {
        $payload = trim($data);
        $declaredMime = null;
        if (stripos($payload, 'data:') === 0) {
            // Split on the first comma rather than regexing the whole URI — the
            // payload after it can be megabytes long.
            $comma = strpos($payload, ',');
            $header = $comma === false ? '' : trim(substr($payload, 5, $comma - 5));
            if ($comma === false || preg_match('/(^|;)base64$/i', $header) !== 1) {
                throw new \InvalidArgumentException('Only base64 data URIs are supported (data:<mime>;base64,…).');
            }
            $declaredMime = strtolower(trim(explode(';', $header)[0]));
            $payload = substr($payload, $comma + 1);
        }
        // Long base64 arrives wrapped (MIME line breaks, pretty-printed JSON).
        $payload = (string) preg_replace('/\s+/', '', $payload);
        if ($payload === '') {
            throw new \InvalidArgumentException('Provide base64-encoded file content in `data`.');
        }

        $cap = $maxBytes ?? self::IMAGE_MAX_BYTES;
        // Cheap pre-decode ceiling (4 base64 chars = 3 bytes) — the exact size
        // is re-checked below, and per-type caps still apply in ingest().
        if (intdiv(\strlen($payload), 4) * 3 > $cap) {
            throw new \InvalidArgumentException(sprintf('Encoded content exceeds the %d MB limit.', intdiv($cap, 1048576)));
        }

        $bytes = base64_decode($payload, true);
        if ($bytes === false) {
            throw new \InvalidArgumentException('`data` is not valid base64.');
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('Empty file.');
        }
        if (\strlen($bytes) > $cap) {
            throw new \InvalidArgumentException(sprintf('File exceeds the %d MB limit.', intdiv($cap, 1048576)));
        }

        return [$bytes, $declaredMime];
    }

    /**
     * Replaces the CONTENT behind an existing media id, keeping the id, the
     * name and the alt — so every `<img data-media-id>` and `{{ filename }}`
     * embed that already points at it now shows the new file with nothing to
     * re-embed. This is the "same picture, new photo" operation; uploading a
     * new item and re-pointing every page is the thing it replaces.
     *
     * Three properties make it safe:
     *
     * 1. **The emitted URL changes.** /media/* and /files/* are served
     *    `immutable` for a year, so reusing the filename would leave every
     *    browser and the CDN serving the old bytes — invisibly, and first of
     *    all to whoever just uploaded the replacement. The revision counter
     *    moves the filename (Media::publicSlug) while the id stays put.
     * 2. **A failure never destroys the working image.** The new original is
     *    written to its own path and the variants are built BEFORE the row is
     *    updated or a single old file is deleted; anything that throws leaves
     *    the previous image on disk, in the database, and still serving.
     * 3. **The kind cannot change.** Swapping an image for a PDF behind an id
     *    that pages embed as `<img>` produces broken pages, so it is refused
     *    with an explanation rather than half-applied.
     */
    public function replace(Site $site, int $id, string $bytes, ?string $originalName = null, ?string $alt = null): Media
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new MediaNotFoundException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }

        // The uploaded filename decides the TYPE and nothing else: the item
        // keeps its own name and slug. Renaming would silently unresolve every
        // `{{ filename }}` embed (FileRenderer matches on original_name /
        // slug.ext / slug, and drops a reference it cannot resolve), so
        // dropping a locally-named "rates-2026.csv" onto "rates.csv" would
        // delete the download link from every page that carried it.
        $name = $originalName !== null && trim($originalName) !== ''
            ? $this->resolveFilename($originalName, null, $bytes)
            : $existing->originalName;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $existing->ext;
        }
        [$mimeAllow, $kind, $maxBytes] = $this->classify($ext);

        if ($kind !== $existing->kind) {
            throw new \InvalidArgumentException(sprintf(
                'Replacing a %s with a %s would break every page that embeds #%d — upload it as a new item instead.',
                $existing->kind,
                $kind,
                $id,
            ));
        }
        // For files the EXTENSION is the contract, not merely the kind: it
        // both names the emitted path and decides how the build renders the
        // reference (.html is inlined verbatim, .js becomes a <script src>,
        // .jsx/.tsx is compiled and mounted, anything else is a download
        // link). Everything unknown classifies as `file`, so the kind check
        // alone would happily turn a working inline widget into a download.
        if ($kind === 'file' && $ext !== $existing->ext) {
            throw new \InvalidArgumentException(sprintf(
                'Replacing .%s with .%s would change how #%d is rendered on every page that embeds it — upload it as a new item instead.',
                $existing->ext,
                $ext,
                $id,
            ));
        }
        if (\strlen($bytes) === 0) {
            throw new \InvalidArgumentException('Empty file.');
        }
        if (\strlen($bytes) > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('File exceeds the %d MB limit for .%s', intdiv($maxBytes, 1048576), $ext));
        }

        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mimeAllow !== null && !\in_array($detected, $mimeAllow, true)) {
            throw new \InvalidArgumentException(sprintf('Content (%s) does not match extension .%s', $detected, $ext));
        }

        // sha256 is UNIQUE per site (ux_media_sha), so a replace that would
        // duplicate another row has to be named rather than left to surface as
        // a constraint violation.
        // findBySha spans trashed rows too, which is the index's own scope.
        $sha = hash('sha256', $bytes);
        $clash = $this->repository->findBySha($site->handle, $sha);
        if ($clash !== null && $clash->id !== $id) {
            throw new \InvalidArgumentException(sprintf(
                'Those exact bytes are already in this library as #%d (%s)%s.',
                $clash->id,
                $clash->originalName,
                $clash->deletedAt !== null ? ', which is in the trash — purge it first to reuse these bytes' : '',
            ));
        }
        if ($clash !== null && $clash->id === $id) {
            // Identical content: a no-op replace must not burn a revision and
            // invalidate a year of cached URLs for nothing.
            throw new \InvalidArgumentException(sprintf('#%d already holds exactly these bytes — nothing to replace.', $id));
        }

        $optimizedAt = null;
        if ($kind === 'image') {
            $optimizer = $this->optimizerFor($site);
            $optimized = $optimizer->optimize($bytes, $name);
            if ($optimized !== $bytes) {
                $optMime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($optimized);
                if (\is_array($mimeAllow) && \in_array($optMime, $mimeAllow, true)) {
                    $bytes = $optimized;
                } else {
                    $this->logger->warning('Image optimizer returned an unexpected type — keeping the original', ['site' => $site->handle, 'mime' => $optMime]);
                }
            }
            if ($optimizer->isConfigured() && !$optimizer->lastAttemptFailed()) {
                $optimizedAt = $this->nowIso();
            }
        }

        // The item's OWN slug — never the uploaded file's — so the emitted
        // path stays the one every embed resolves to.
        $revision = $existing->revision + 1;
        $canonicalExt = $ext === 'jpeg' ? 'jpg' : $ext;
        $sitePath = $this->databases->sitePath($site->handle);
        $subdir = date('Y') . '/' . date('m');
        $originalsDir = $sitePath . '/media/originals/' . $subdir;
        if (!is_dir($originalsDir) && !mkdir($originalsDir, 0770, true) && !is_dir($originalsDir)) {
            throw new \RuntimeException('Could not create media directory.');
        }

        // Its own path (the revision is in the filename), so the previous
        // original is untouched until this one is known good.
        $publicSlug = $existing->slug . '-r' . $revision;
        $relativeOriginal = 'media/originals/' . $subdir . '/' . $id . '-' . $publicSlug . '.' . $canonicalExt;
        $originalPath = $sitePath . '/' . $relativeOriginal;
        // Two replaces of the same id race for this exact path and both would
        // claim revision N+1, so the loser's variants would be built from the
        // winner's bytes. Cheap guard: the path is unique per revision, so its
        // existence means another replace of this item is already in flight.
        if (is_file($originalPath)) {
            throw new \RuntimeException(sprintf('A replacement for #%d is already being processed — try again in a moment.', $id));
        }
        if (file_put_contents($originalPath, $bytes) === false) {
            throw new \RuntimeException('Could not write the replacement file to disk.');
        }

        if ($kind === 'image') {
            try {
                // Writes NEW variant files (revisioned names) alongside the old
                // ones and swaps the variant rows; the old files are deleted
                // below, once the row itself points at this revision.
                $this->generateVariants($site, $id, $publicSlug, $originalPath, $sitePath);
            } catch (\Throwable $e) {
                @unlink($originalPath);
                $this->logger->error('Media replace failed — the previous file is untouched', ['site' => $site->handle, 'media' => $id, 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        // original_name and slug are deliberately NOT written: they are the
        // identity every embed resolves on.
        $fields = [
            'ext' => $canonicalExt,
            'mime' => $detected,
            'sha256' => $sha,
            'bytes' => \strlen($bytes),
            'disk_path' => $relativeOriginal,
            'revision' => $revision,
            'status' => 'ready',
            'optimized_at' => $optimizedAt,
            // A crop was drawn on the PREVIOUS image. Re-applying that rect to
            // different bytes frames something nobody chose — and on a
            // differently-shaped replacement it can cut out the subject
            // entirely. New bytes, new framing decision.
            'crop_x' => null,
            'crop_y' => null,
            'crop_w' => null,
            'crop_h' => null,
            'resize_width' => null,
        ];
        if ($alt !== null && trim($alt) !== '') {
            $fields['alt'] = mb_substr(trim($alt), 0, 1000);
        }
        $this->repository->update($site->handle, $id, $fields);

        // Only now that the row is the new revision: drop the previous
        // revision's files. A failure here is disk litter, never a broken page.
        foreach ($existing->variants as $variant) {
            $old = $sitePath . '/' . ltrim($variant->diskPath, '/');
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $previousOriginal = $sitePath . '/' . ltrim($existing->diskPath, '/');
        if ($existing->diskPath !== $relativeOriginal && is_file($previousOriginal)) {
            @unlink($previousOriginal);
        }

        // An existing alt stands — it is usually still true of the new file,
        // and silently regenerating it would overwrite the author's words (and
        // spend AI credits) on every replace. An empty one is filled exactly
        // as an upload would fill it.
        if ($kind === 'image' && !$existing->decorative && trim((string) ($fields['alt'] ?? $existing->alt)) === '') {
            $this->autoAlt($site, $id);
        }

        $media = $this->repository->findById($site->handle, $id);
        \assert($media !== null);
        $this->logger->info('Media replaced', ['site' => $site->handle, 'media' => $id, 'revision' => $revision, 'bytes' => \strlen($bytes)]);

        return $media;
    }

    /**
     * Replace #id with the bytes at a URL (same SSRF guards as the import).
     *
     * The ceiling follows the ITEM's kind, not the image default: this is
     * reachable for files and video too, where a 15 MB cap would reject a
     * perfectly legal 30 MB PDF while naming a limit that kind does not have.
     */
    public function replaceFromUrl(Site $site, int $id, string $url, ?string $alt = null, ?int $maxBytes = null, string $filename = ''): Media
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new MediaNotFoundException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }
        $cap = $maxBytes ?? match ($existing->kind) {
            'video' => self::VIDEO_MAX,
            'file' => self::FILE_MAX,
            default => self::IMAGE_MAX,
        };

        $result = $this->fetcher->fetch($url, $cap);
        // An explicit filename wins: it is the caller saying what type these
        // bytes are, and the URL's basename is frequently a content hash.
        $name = trim($filename) !== '' ? $filename : (basename((string) parse_url($result['final_url'], PHP_URL_PATH)) ?: '');
        if ($name !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.' . $this->extForMime($result['mime']);
        }

        // No usable name → the item's existing one decides the type.
        return $this->replace($site, $id, $result['body'], $name !== '' ? $name : null, $alt);
    }

    /** Replace #id with base64 bytes — for a caller that holds the file. */
    public function replaceFromBase64(Site $site, int $id, string $data, string $filename = '', ?string $alt = null, ?int $maxBytes = null): Media
    {
        $existing = $this->repository->findById($site->handle, $id);
        if ($existing === null) {
            throw new MediaNotFoundException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }
        $maxBytes ??= match ($existing->kind) {
            'video' => self::VIDEO_MAX,
            'file' => self::FILE_MAX,
            default => self::IMAGE_MAX,
        };
        [$bytes, $declaredMime] = $this->decodeBase64($data, $maxBytes);
        $name = trim($filename) !== '' ? $this->resolveFilename($filename, $declaredMime, $bytes) : null;

        return $this->replace($site, $id, $bytes, $name, $alt);
    }

    /**
     * The original_name for a caller-supplied filename: no path, no control
     * characters, and always an extension — that extension is what classify()
     * dispatches on, so an omitted filename is resolved from the bytes
     * themselves (falling back to a data URI's declared type).
     */
    private function resolveFilename(string $filename, ?string $declaredMime, string $bytes): string
    {
        $name = basename(str_replace('\\', '/', trim($filename)));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $name));
        $name = mb_substr($name, 0, 200);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload';
        }
        if (pathinfo($name, PATHINFO_EXTENSION) !== '') {
            return $name;
        }

        // Sniffing first keeps the extension consistent with the bytes (which
        // ingest() then re-verifies); a declared type only fills the gap.
        $ext = $this->extForMime((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if ($ext === 'bin' && $declaredMime !== null && $declaredMime !== '') {
            $ext = $this->extForMime($declaredMime);
        }

        return $name . '.' . $ext;
    }

    /**
     * Re-encodes an EXISTING image's variants from its stored original with
     * the site's CURRENT compression/quality settings. No ShortPixel call, no
     * credits — this is how a changed image_compression (or a fallback-format
     * fix) reaches images uploaded earlier. Throws on failure; the row and the
     * original are never touched.
     */
    public function regenerateVariants(Site $site, int $id): void
    {
        $media = $this->repository->findById($site->handle, $id);
        if ($media === null || $media->kind !== 'image') {
            throw new \RuntimeException(sprintf('Image #%d not found on site "%s".', $id, $site->handle));
        }
        if ($media->status !== 'ready') {
            throw new \RuntimeException(sprintf('Image #%d is not ready (status: %s).', $id, $media->status));
        }

        $sitePath = $this->databases->sitePath($site->handle);
        $originalPath = str_starts_with($media->diskPath, $sitePath)
            ? $media->diskPath
            : $sitePath . '/' . ltrim($media->diskPath, '/');
        if (!is_file($originalPath)) {
            throw new \RuntimeException(sprintf('Original file for image #%d is missing on disk.', $id));
        }

        // publicSlug, not slug: a replaced image's variants are named for its
        // revision, and a regeneration must not rename them back to revision
        // 0's filenames (which the CDN still holds under the old content).
        // The stored crop/resize is re-applied: it lives in the database, not
        // in the pixels, so a regeneration that ignored it would silently
        // un-crop every cropped image on the site.
        $this->generateVariants($site, $id, $media->publicSlug(), $originalPath, $sitePath, $media->sharpTransform());
    }

    /**
     * Set (or clear) an image's non-destructive crop and target width.
     *
     * The uploaded original is NEVER modified — the rect is stored and
     * re-applied by the sharp pipeline on every build — so a bad drag costs
     * nothing and the framing stays re-drawable forever. Pass nulls to clear.
     *
     * The revision still has to move. `/media/*` is served `immutable` for a
     * year, so the BYTES behind an unchanged filename would keep being served
     * from caches that never revalidate; bumping the revision renames the
     * emitted files exactly as a replace does, which is also what makes every
     * page already embedding this id show the new framing with nothing to
     * re-embed.
     *
     * `$crop` is deliberately typed loose: it arrives from a drag in a
     * browser via a form post, so the keys are exactly what this method has
     * to verify rather than assume.
     *
     * @param array<string, mixed>|null $crop fractions of the original, 0..1
     */
    public function transform(Site $site, int $id, ?array $crop, ?int $resizeWidth): Media
    {
        $media = $this->repository->findById($site->handle, $id);
        if ($media === null) {
            throw new MediaNotFoundException(sprintf('Media #%d not found on site "%s".', $id, $site->handle));
        }
        if ($media->kind !== 'image') {
            throw new \InvalidArgumentException(sprintf('#%d is a %s — only images can be cropped.', $id, $media->kind));
        }
        if ($media->status !== 'ready') {
            throw new \InvalidArgumentException(sprintf('#%d is not ready yet (status: %s).', $id, $media->status));
        }

        if ($crop !== null) {
            // Validated here rather than left to sharp: a zero-area or
            // out-of-range rect is a bad request, and the node worker's
            // "extract_area: bad extract area" is not an answer anyone can act
            // on. sharp still clamps — this is the readable gate, not the only
            // one.
            foreach (['x', 'y', 'w', 'h'] as $key) {
                if (!isset($crop[$key]) || !is_finite((float) $crop[$key])) {
                    throw new \InvalidArgumentException('A crop needs x, y, w and h as fractions of the image.');
                }
            }
            $crop = ['x' => (float) $crop['x'], 'y' => (float) $crop['y'], 'w' => (float) $crop['w'], 'h' => (float) $crop['h']];
            if ($crop['w'] <= 0.0 || $crop['h'] <= 0.0) {
                throw new \InvalidArgumentException('The crop has no area — drag a region on the image first.');
            }
            $crop['x'] = max(0.0, min(1.0, $crop['x']));
            $crop['y'] = max(0.0, min(1.0, $crop['y']));
            $crop['w'] = min($crop['w'], 1.0 - $crop['x']);
            $crop['h'] = min($crop['h'], 1.0 - $crop['y']);
            if ($crop['w'] <= 0.0 || $crop['h'] <= 0.0) {
                throw new \InvalidArgumentException('That crop falls outside the image.');
            }
        }

        if ($resizeWidth !== null && $resizeWidth <= 0) {
            $resizeWidth = null; // "no cap", not "zero pixels wide"
        }

        $fields = [
            'crop_x' => $crop['x'] ?? null,
            'crop_y' => $crop['y'] ?? null,
            'crop_w' => $crop['w'] ?? null,
            'crop_h' => $crop['h'] ?? null,
            'resize_width' => $resizeWidth,
        ];
        if ($crop === null && $resizeWidth === null && $media->sharpTransform() === null) {
            throw new \InvalidArgumentException(sprintf('#%d has no crop to clear.', $id));
        }

        // The row is written FIRST so regenerateVariants reads the new
        // transform, and the revision moves with it so the emitted filenames
        // change in the same step. A failure below leaves the row describing a
        // framing whose files are not built yet, which the next publish fixes
        // — the inverse (files nobody's row points at) would serve the wrong
        // framing from an immutable cache for a year.
        $fields['revision'] = $media->revision + 1;
        $this->repository->update($site->handle, $id, $fields);

        $previous = $media->variants;
        $this->regenerateVariants($site, $id);

        // The old revision's files are only dropped once the new ones exist.
        $sitePath = $this->databases->sitePath($site->handle);
        foreach ($previous as $variant) {
            $old = $sitePath . '/' . ltrim($variant->diskPath, '/');
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $updated = $this->repository->findById($site->handle, $id);
        \assert($updated !== null);

        return $updated;
    }

    /** Ready, non-trashed images — the work list for variant regeneration. */
    public function imageIds(string $siteHandle): array
    {
        return $this->repository->readyImageIds($siteHandle);
    }

    /**
     * Retroactively optimizes an EXISTING image (uploaded before a ShortPixel
     * key was configured): runs the stored original through the site's
     * optimizer and, when it shrank, swaps the file and regenerates every
     * AVIF/WebP variant from the smaller source. optimized_at is set on any
     * SUCCESSFUL attempt — including "no further gain" — so credits are spent
     * at most once per image; a transport failure throws and leaves the row
     * unmarked (retryable). sha256 stays the incoming-bytes hash (the dedupe
     * identity), exactly as ingest-time optimization behaves.
     *
     * @return array{changed: bool, saved: int} saved = bytes shaved off the original
     */
    public function optimizeExisting(Site $site, int $id): array
    {
        $media = $this->repository->findById($site->handle, $id);
        if ($media === null || $media->kind !== 'image') {
            throw new \RuntimeException(sprintf('Image #%d not found on site "%s".', $id, $site->handle));
        }
        if ($media->status !== 'ready') {
            throw new \RuntimeException(sprintf('Image #%d is not ready (status: %s).', $id, $media->status));
        }
        if ($media->optimizedAt !== null) {
            return ['changed' => false, 'saved' => 0]; // already done — never bill twice
        }

        $optimizer = $this->optimizerFor($site);
        if (!$optimizer->isConfigured()) {
            throw new \RuntimeException('No image optimizer is configured — set a ShortPixel key first.');
        }

        $sitePath = $this->databases->sitePath($site->handle);
        $originalPath = str_starts_with($media->diskPath, $sitePath)
            ? $media->diskPath
            : $sitePath . '/' . ltrim($media->diskPath, '/');
        $bytes = is_file($originalPath) ? (string) file_get_contents($originalPath) : '';
        if ($bytes === '') {
            throw new \RuntimeException(sprintf('Original file for image #%d is missing on disk.', $id));
        }

        $optimized = $optimizer->optimize($bytes, $media->originalName);
        if ($optimizer->lastAttemptFailed()) {
            throw new \RuntimeException(sprintf(
                'Optimization of image #%d failed: %s — it was left untouched and can be retried.',
                $id,
                $optimizer->lastError() ?? 'no response',
            ));
        }

        $gain = \strlen($optimized) < \strlen($bytes);
        if ($gain) {
            // Same guard as ingest: a bad optimizer response must never
            // replace the stored original.
            $mimeAllow = self::IMAGE_MIME[$media->ext] ?? self::IMAGE_MIME['jpg'];
            $optMime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($optimized);
            if (!\in_array($optMime, $mimeAllow, true)) {
                $this->logger->warning('Image optimizer returned an unexpected type — keeping the original', ['site' => $site->handle, 'media' => $id, 'mime' => $optMime]);
                $gain = false;
            }
        }

        if (!$gain) {
            $this->repository->update($site->handle, $id, ['optimized_at' => $this->nowIso()]);

            return ['changed' => false, 'saved' => 0];
        }

        // Swap the original, then rebuild the variants from it; on failure put
        // the previous bytes back and stay unmarked so the attempt can rerun.
        file_put_contents($originalPath, $optimized);
        try {
            // The stored crop/resize has to ride along: optimization rebuilds
            // every variant, and rebuilding them from an untransformed source
            // would quietly un-crop the image as a side effect of squeezing
            // its bytes.
            $this->generateVariants($site, $id, $media->publicSlug(), $originalPath, $sitePath, $media->sharpTransform());
        } catch (\Throwable $e) {
            file_put_contents($originalPath, $bytes);
            $this->logger->error('Variant regeneration after optimization failed — original restored', ['site' => $site->handle, 'media' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
        $this->repository->update($site->handle, $id, [
            'bytes' => \strlen($optimized),
            'optimized_at' => $this->nowIso(),
        ]);
        $this->logger->info('Optimized existing image', ['site' => $site->handle, 'media' => $id, 'from' => \strlen($bytes), 'to' => \strlen($optimized)]);

        return ['changed' => true, 'saved' => \strlen($bytes) - \strlen($optimized)];
    }

    /**
     * Generates and stores alt text for an image: vision AI over the smallest
     * web-format variant (tiny prompt payload) when the site has an AI
     * credential, else a humanized filename. Never throws — an upload or
     * backfill must not fail over alt. Returns the stored alt, or null when
     * the row is not an eligible image.
     */
    public function autoAlt(Site $site, int $mediaId): ?string
    {
        $media = $this->repository->findById($site->handle, $mediaId);
        if ($media === null || $media->kind !== 'image' || $media->decorative) {
            return null;
        }

        $alt = null;
        try {
            $generator = $this->ai?->altTextForSite($site);
            if ($generator !== null && $generator->isConfigured()) {
                [$bytes, $mime] = $this->smallestImagePayload($site, $media);
                if ($bytes !== null) {
                    $alt = $generator->generate($bytes, (string) $mime, $media->originalName);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Alt-text generation failed — falling back to the filename', ['site' => $site->handle, 'id' => $mediaId, 'error' => $e->getMessage()]);
        }

        $alt ??= self::humanizeFilename($media->originalName);
        $this->repository->update($site->handle, $mediaId, ['alt' => mb_substr($alt, 0, 1000)]);
        $this->logger->info('Alt text auto-generated', ['site' => $site->handle, 'id' => $mediaId, 'alt' => $alt]);

        return $alt;
    }

    /**
     * The cheapest bytes that still show the image: the smallest webp/jpg/png
     * variant, else the original when it is reasonably small.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function smallestImagePayload(Site $site, \Fastsite\Media\Dto\Media $media): array
    {
        $sitePath = $this->databases->sitePath($site->handle);
        $best = null;
        foreach ($media->variants as $variant) {
            if (!\in_array($variant->format, ['webp', 'jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            if ($best === null || $variant->bytes < $best->bytes) {
                $best = $variant;
            }
        }
        if ($best !== null && is_file($sitePath . '/' . $best->diskPath)) {
            $mime = $best->format === 'webp' ? 'image/webp' : ($best->format === 'png' ? 'image/png' : 'image/jpeg');

            return [(string) file_get_contents($sitePath . '/' . $best->diskPath), $mime];
        }

        $originalPath = $sitePath . '/' . $media->diskPath;
        if (is_file($originalPath) && filesize($originalPath) <= 4_194_304 && \in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return [(string) file_get_contents($originalPath), $media->mime];
        }

        return [null, null];
    }

    /** "hero-image_v2 final.png" → "Hero image v2 final" — the no-AI fallback. */
    public static function humanizeFilename(string $originalName): string
    {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[\s_\-]+/', ' ', $name));

        return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : 'Image';
    }

    private function processImage(Site $site, int $mediaId, string $slug, string $originalPath, string $sitePath): void
    {
        try {
            $this->generateVariants($site, $mediaId, $slug, $originalPath, $sitePath);
        } catch (\Throwable $e) {
            // Roll the ingest back so a failed attempt does NOT claim the
            // sha256 dedupe slot (an identical re-upload must reprocess) and
            // does not leave an orphaned original on disk.
            $this->repository->delete($site->handle, $mediaId);
            @unlink($originalPath);
            $this->cleanupVariants($sitePath, $mediaId);
            $this->logger->error('Media processing failed', ['site' => $site->handle, 'media' => $mediaId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Runs the sharp pipeline for an EXISTING row's original and swaps in the
     * fresh variants. Throws on failure without touching the row — ingest adds
     * its rollback in processImage; re-optimization must keep the row alive.
     */
    /**
     * @param array{crop: array{x: float, y: float, w: float, h: float}|null, width: int|null}|null $transform
     *        the stored NON-DESTRUCTIVE crop/resize, re-applied on every build
     */
    private function generateVariants(Site $site, int $mediaId, string $slug, string $originalPath, string $sitePath, ?array $transform = null): void
    {
        $variantsDir = $sitePath . '/media/variants/' . $mediaId;
        $job = [
            'transform' => $transform,
            'original' => $originalPath,
            'kind' => 'image',
            'outDir' => $variantsDir,
            'slug' => $slug,
            'widths' => self::RESPONSIVE_WIDTHS,
            'maxWidth' => $site->imageMaxWidth,
            'quality' => $this->variantQualityFor($site),
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
