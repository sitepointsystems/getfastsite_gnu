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
 */
final class ShortPixelOptimizer implements ImageOptimizer
{
    private const ENDPOINT = 'https://api.shortpixel.com/v2/post-reducer.php';
    private const WAIT_SECONDS = 30;   // server-side synchronous wait
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly int $lossy = 1, // 1 = lossy, 2 = glossy, 0 = lossless
    ) {
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

        try {
            $optimizedUrl = $this->submit($bytes, $filename);
            if ($optimizedUrl === null) {
                return $bytes; // still processing / error — keep original
            }
            $optimized = $this->download($optimizedUrl);
            // Only accept a genuinely smaller result.
            if ($optimized !== null && $optimized !== '' && \strlen($optimized) < \strlen($bytes)) {
                $this->logger->info('ShortPixel optimized an image', ['from' => \strlen($bytes), 'to' => \strlen($optimized)]);

                return $optimized;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ShortPixel optimize failed — keeping the original', ['error' => $e->getMessage()]);
        }

        return $bytes;
    }

    /** Submits the image; returns the optimized-file URL, or null. */
    private function submit(string $bytes, string $filename): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shortpixel');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $bytes);

        try {
            $post = [
                'key' => $this->apiKey,
                'lossy' => (string) $this->lossy,
                'wait' => (string) self::WAIT_SECONDS,
                'convertto' => '',
                'file1' => new \CURLFile($tmp, 'application/octet-stream', basename($filename) ?: 'image'),
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
            curl_close($ch);

            if ($response === false || $status < 200 || $status >= 300) {
                return null;
            }

            /** @var mixed $data */
            $data = json_decode((string) $response, true);
            if (!\is_array($data) || !isset($data[0]) || !\is_array($data[0])) {
                return null;
            }
            $item = $data[0];
            $code = (string) ($item['Status']['Code'] ?? '');
            if ($code !== '2') {
                // 1 = still processing, negative = error — keep original.
                return null;
            }

            $url = (string) ($item['LossyURL'] ?? $item['LosslessURL'] ?? '');

            return $url !== '' ? $url : null;
        } finally {
            @unlink($tmp);
        }
    }

    private function download(string $url): ?string
    {
        // Pin to *.shortpixel.com by host SUFFIX (a substring test would let
        // shortpixel.com.attacker.com through). Never follow redirects.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_starts_with($url, 'https://') || !($host === 'shortpixel.com' || str_ends_with($host, '.shortpixel.com'))) {
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
        curl_close($ch);

        return ($body !== false && $status === 200) ? (string) $body : null;
    }
}
