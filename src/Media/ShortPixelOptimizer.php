<?php

declare(strict_types=1);

namespace Fastsite\Media;

use Psr\Log\LoggerInterface;

/**
 * Optimizes an image through ShortPixel's post-reducer API on landing, before
 * sharp generates the AVIF/WebP variants (so every variant derives from the
 * best-compressed original). Fails SAFE: any error, timeout, or larger result
 * returns the original bytes unchanged — a ShortPixel outage never blocks an
 * upload. The host is fixed (api.shortpixel.com), so no SSRF surface.
 *
 * Contract notes (learned the hard way): `file_paths` is REQUIRED alongside
 * the upload (missing it = -117 on every call), error responses arrive as a
 * bare {"Status":{...}} OBJECT while results come as an array of items, and
 * Status.Code 1 means still-processing — re-polled via reducer.php with the
 * returned OriginalURL, never by re-uploading.
 */
final class ShortPixelOptimizer implements ImageOptimizer
{
    private const ENDPOINT = 'https://api.shortpixel.com/v2/post-reducer.php';
    private const POLL_ENDPOINT = 'https://api.shortpixel.com/v2/reducer.php';
    private const WAIT_SECONDS = 30;   // server-side synchronous wait
    private const POLL_WAIT_SECONDS = 10;
    private const PENDING_POLLS = 2;
    private const MAX_BYTES = 25 * 1024 * 1024;

    private bool $lastFailed = false;
    private ?string $lastError = null;
    private ?string $downloadError = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly int $lossy = 1, // 1 = lossy, 2 = glossy, 0 = lossless
    ) {
    }

    /** Same key/logger at a different compression level (per-site choice). */
    public function withLossy(int $lossy): self
    {
        return $lossy === $this->lossy ? $this : new self($this->apiKey, $this->logger, $lossy);
    }

    public function lastAttemptFailed(): bool
    {
        return $this->lastFailed;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function describe(): string
    {
        return 'ShortPixel (' . ($this->lossy === 0 ? 'lossless' : ($this->lossy === 2 ? 'glossy' : 'lossy')) . ')';
    }

    public function optimize(string $bytes, string $filename): string
    {
        if ($this->apiKey === '' || $bytes === '') {
            return $bytes;
        }

        // Failed until ShortPixel demonstrably answered — so an API outage is
        // distinguishable from "already optimal" via lastAttemptFailed(), and
        // lastError() carries the coded reason into flashes and logs.
        $this->lastFailed = true;
        $this->lastError = 'no response from ShortPixel';

        try {
            $item = $this->submit($bytes, $filename);

            // Still processing after the synchronous wait: poll by the
            // returned OriginalURL (re-uploading would start over).
            for ($poll = 0; $item !== null && (string) ($item['Status']['Code'] ?? '') === '1' && $poll < self::PENDING_POLLS; ++$poll) {
                $originalUrl = (string) ($item['OriginalURL'] ?? '');
                if ($originalUrl === '') {
                    break;
                }
                sleep(2);
                $item = $this->poll($originalUrl) ?? $item;
            }

            if ($item !== null) {
                $code = (string) ($item['Status']['Code'] ?? '');
                if ($code === '2') {
                    $url = (string) ($item['LossyURL'] ?? $item['LosslessURL'] ?? '');
                    if ($url === '') {
                        $this->lastError = 'ShortPixel reported success but sent no download URL';

                        $this->logger->warning('ShortPixel optimize failed — keeping the original', ['file' => $filename, 'error' => $this->lastError]);

                        return $bytes;
                    }
                    $optimized = $this->download($url);
                    if ($optimized !== null && $optimized !== '') {
                        $this->lastFailed = false;
                        $this->lastError = null;
                        // Only accept a genuinely smaller result.
                        if (\strlen($optimized) < \strlen($bytes)) {
                            $this->logger->info('ShortPixel optimized an image', ['from' => \strlen($bytes), 'to' => \strlen($optimized)]);

                            return $optimized;
                        }

                        return $bytes; // successful answer, no further gain
                    }
                    $this->lastError = 'optimized file could not be downloaded'
                        . ($this->downloadError !== null ? ' (' . $this->downloadError . ')' : '');
                } elseif ($code === '1') {
                    $this->lastError = 'still processing on ShortPixel — try again in a moment';
                } else {
                    $this->lastError = 'ShortPixel ' . $code . ': ' . (string) ($item['Status']['Message'] ?? 'unknown error');
                }
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        $this->logger->warning('ShortPixel optimize failed — keeping the original', ['file' => $filename, 'error' => $this->lastError]);

        return $bytes;
    }

    /**
     * Uploads the image; returns the result item (whatever its Status.Code),
     * or null on a transport/shape failure with lastError set.
     *
     * @return array<string, mixed>|null
     */
    private function submit(string $bytes, string $filename): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shortpixel');
        if ($tmp === false) {
            $this->lastError = 'could not create a temp file';

            return null;
        }
        file_put_contents($tmp, $bytes);

        $name = basename($filename) ?: 'image';

        try {
            $post = [
                'key' => $this->apiKey,
                'lossy' => (string) $this->lossy,
                'wait' => (string) self::WAIT_SECONDS,
                'convertto' => '',
                // REQUIRED with uploads — the key must match the file field
                // name; omitting it is a guaranteed -117 on every call.
                'file_paths' => json_encode(['file1' => $name], JSON_THROW_ON_ERROR),
                'file1' => new \CURLFile($tmp, 'application/octet-stream', $name),
            ];

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => self::WAIT_SECONDS + 20,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $status < 200 || $status >= 300) {
                $this->lastError = $curlError !== '' ? 'transport: ' . $curlError : 'HTTP ' . $status . ' from ShortPixel';

                return null;
            }

            return $this->parseItem((string) $response);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Checks a pending upload's status via reducer.php (urllist), without
     * re-uploading. Returns the item or null (transport/shape failure).
     *
     * @return array<string, mixed>|null
     */
    private function poll(string $originalUrl): ?array
    {
        $payload = json_encode([
            'key' => $this->apiKey,
            'lossy' => (string) $this->lossy,
            'wait' => (string) self::POLL_WAIT_SECONDS,
            'urllist' => [$originalUrl],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init(self::POLL_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::POLL_WAIT_SECONDS + 20,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return null; // keep the previous item; its code-1 state reports "still processing"
        }

        return $this->parseItem((string) $response);
    }

    /**
     * Results arrive as an ARRAY of items; errors (bad key, missing params)
     * as a bare {"Status": {...}} OBJECT. Normalize both to one item.
     *
     * @return array<string, mixed>|null
     */
    private function parseItem(string $body): ?array
    {
        /** @var mixed $data */
        $data = json_decode($body, true);
        if (\is_array($data) && isset($data[0]) && \is_array($data[0])) {
            return $data[0];
        }
        if (\is_array($data) && isset($data['Status']) && \is_array($data['Status'])) {
            return $data;
        }
        $this->lastError = 'unexpected ShortPixel response: ' . mb_substr(trim($body), 0, 160);

        return null;
    }

    private function download(string $url): ?string
    {
        $this->downloadError = null;

        // Up to 2 host-revalidated redirect hops; curl itself never follows
        // (CURLOPT_FOLLOWLOCATION off) so no hop can escape the pin.
        for ($hop = 0; $hop < 3; ++$hop) {
            // The API frequently hands back plain-http result URLs; upgrade the
            // scheme — the pinned host serves TLS. Then pin to *.shortpixel.com
            // by host SUFFIX (a substring test would let
            // shortpixel.com.attacker.com through).
            if (str_starts_with($url, 'http://')) {
                $url = 'https://' . substr($url, 7);
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!str_starts_with($url, 'https://') || !($host === 'shortpixel.com' || str_ends_with($host, '.shortpixel.com'))) {
                $this->downloadError = 'refused non-ShortPixel URL ' . $url;

                return null;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_BUFFERSIZE => 65536,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static fn ($ch, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_BYTES ? 1 : 0,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $status === 200) {
                return (string) $body;
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $location; // next hop re-validates scheme + host above

                continue;
            }
            $this->downloadError = ($curlError !== '' ? 'transport: ' . $curlError : 'HTTP ' . $status) . ' fetching ' . $url;

            return null;
        }
        $this->downloadError = 'too many redirects fetching ' . $url;

        return null;
    }
}
