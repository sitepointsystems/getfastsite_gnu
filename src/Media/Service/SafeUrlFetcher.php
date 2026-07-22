<?php

declare(strict_types=1);

namespace Fastsite\Media\Service;

/**
 * SSRF-guarded remote fetch for upload-from-URL. Resolves DNS ourselves,
 * rejects private/reserved ranges (IPv4 AND IPv6), pins the connection to
 * the vetted IP so DNS rebinding can't swap it, re-validates on redirect,
 * and caps the download size. Never trusts a URL from content.
 */
final class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly int $defaultMaxBytes = 25 * 1024 * 1024)
    {
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
            $ip = $this->resolveAndVet($parts['host']);

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

    private function resolveAndVet(string $host): string
    {
        // A bare IP literal is vetted directly; a hostname is resolved.
        $candidates = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolve($host);

        foreach ($candidates as $ip) {
            if ($this->isPublic($ip)) {
                return $ip;
            }
        }

        throw new \InvalidArgumentException('Refusing to fetch from a private, loopback, or reserved address.');
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            throw new \InvalidArgumentException('Could not resolve host: ' . $host);
        }

        return $ips;
    }

    /**
     * Special-use ranges PHP's NO_PRIV_RANGE|NO_RES_RANGE flags do NOT
     * cover — CGNAT, protocol assignments, benchmarking, 6to4, NAT64.
     */
    private const BLOCKED_CIDRS = [
        '100.64.0.0/10',   // RFC 6598 CGNAT (K8s pod/node nets)
        '192.0.0.0/24',    // IETF protocol assignments (incl. NAT64 discovery)
        '198.18.0.0/15',   // benchmarking
        '192.88.99.0/24',  // 6to4 relay anycast
        '2002::/16',       // 6to4
        '64:ff9b::/96',    // NAT64
        '64:ff9b:1::/48',  // NAT64 local-use
    ];

    private function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) must be judged on its
        // embedded IPv4 so a blocked v4 can't be smuggled in v6 form.
        if (\strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            return $this->isPublic(inet_ntop(substr($packed, 12)) ?: '0.0.0.0');
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = @inet_pton($net);
        if ($netPacked === false || \strlen($netPacked) !== \strlen($packed)) {
            return false; // different family — can't match
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($packed, $netPacked, $fullBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (\ord($packed[$fullBytes]) & $mask) === (\ord($netPacked[$fullBytes]) & $mask);
    }
}
