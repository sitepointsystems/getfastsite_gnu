<?php

declare(strict_types=1);

namespace Fastsite\Media\Service;

use Fastsite\Shared\Net\HostVetter;

/**
 * SSRF-guarded remote fetch for upload-from-URL. Resolves DNS ourselves and
 * rejects private/reserved ranges (IPv4 AND IPv6) through the shared
 * HostVetter, pins the connection to the vetted IP so DNS rebinding can't
 * swap it, re-validates on redirect, and caps the download size. Never
 * trusts a URL from content.
 */
final class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 3;

    private readonly HostVetter $hostVetter;

    public function __construct(private readonly int $defaultMaxBytes = 25 * 1024 * 1024)
    {
        $this->hostVetter = new HostVetter();
    }

    /**
     * @param int|null $maxBytes overrides the default ceiling for this call
     *                            (caller passes the per-type media cap)
     * @return array{body: string, mime: string, final_url: string}
     */
    public function fetch(string $url, ?int $maxBytes = null): array
    {
        $maxBytes ??= $this->defaultMaxBytes;
        $redirects = 0;

        while (true) {
            $parts = $this->validateUrl($url);
            $ip = $this->hostVetter->vet($parts['host']);

            $ch = curl_init();
            // Hard byte ceiling enforced per write chunk — memory is bounded
            // to maxBytes + one chunk regardless of the response's real size
            // (an attacker can't stream gigabytes into a PHP string).
            $body = '';
            $overflow = false;
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => false, // we handle redirects ourselves
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // Pin the resolved IP so the socket cannot be rebound.
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $parts['host'], $parts['port'], $ip)],
                CURLOPT_HEADER => false,
                CURLOPT_USERAGENT => 'fastsite/1.0 (+media-import)',
                CURLOPT_BUFFERSIZE => 65536,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$overflow, $maxBytes): int {
                    $body .= $chunk;
                    if (\strlen($body) > $maxBytes) {
                        $overflow = true;

                        return 0; // signal error to curl → aborts the transfer
                    }

                    return \strlen($chunk);
                },
            ]);

            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            curl_close($ch);

            if ($overflow) {
                throw new \RuntimeException('Remote file exceeds the size limit.');
            }
            if ($ok === false) {
                throw new \RuntimeException('Remote fetch failed: ' . ($error !== '' ? $error : 'unknown error'));
            }

            if ($status >= 300 && $status < 400 && $redirectUrl !== '') {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Too many redirects while fetching media.');
                }
                $url = $redirectUrl;
                continue; // re-validate the new host from scratch
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Remote returned HTTP ' . $status);
            }

            return ['body' => $body, 'mime' => strtok($mime, ';') ?: '', 'final_url' => $url];
        }
    }

    /**
     * @return array{host: string, port: int}
     */
    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http/https URLs are allowed.');
        }

        return [
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
        ];
    }
}
