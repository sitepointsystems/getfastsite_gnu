<?php

declare(strict_types=1);

namespace Fastsite\Backup\Storage;

/**
 * Minimal S3-compatible client for Cloudflare R2 (AWS SigV4). Only the verbs
 * the backup pipeline needs: PUT/GET/HEAD/DELETE object + ListObjectsV2. No
 * SDK dependency — R2 uses region "auto", service "s3".
 */
final class S3Client implements StorageClient
{
    private const REGION = 'auto';
    private const SERVICE = 's3';

    public function __construct(
        private readonly string $endpoint,     // https://{account}.r2.cloudflarestorage.com
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->bucket !== '' && $this->accessKey !== '' && $this->secretKey !== '';
    }

    public function describe(): string
    {
        return 'R2 bucket ' . $this->bucket;
    }

    public function put(string $key, string $bytes): void
    {
        $r = $this->request('PUT', $key, $bytes);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException(sprintf('R2 PUT %s failed: HTTP %d %s', $key, $r['status'], mb_substr($r['body'], 0, 200)));
        }
    }

    public function get(string $key): ?string
    {
        $r = $this->request('GET', $key);
        if ($r['status'] === 404) {
            return null;
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException(sprintf('R2 GET %s failed: HTTP %d', $key, $r['status']));
        }

        return $r['body'];
    }

    public function exists(string $key): bool
    {
        return $this->request('HEAD', $key)['status'] === 200;
    }

    public function delete(string $key): void
    {
        $r = $this->request('DELETE', $key);
        // DELETE is idempotent — 404 means already gone. Anything else
        // non-2xx must throw so prune keeps the row and retries later.
        if ($r['status'] !== 404 && ($r['status'] < 200 || $r['status'] >= 300)) {
            throw new \RuntimeException(sprintf('R2 DELETE %s failed: HTTP %d', $key, $r['status']));
        }
    }

    public function list(string $prefix): array
    {
        $keys = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $r = $this->request('GET', '', null, $query);
            if ($r['status'] < 200 || $r['status'] >= 300) {
                throw new \RuntimeException('R2 list failed: HTTP ' . $r['status']);
            }
            $xml = simplexml_load_string($r['body']);
            if ($xml === false) {
                break;
            }
            foreach ($xml->Contents ?? [] as $obj) {
                $keys[] = (string) $obj->Key;
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null);

        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $key, ?string $body = null, array $query = []): array
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $path = '/' . $this->bucket . ($key !== '' ? '/' . str_replace('%2F', '/', rawurlencode($key)) : '');

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body ?? '');

        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . $v . "\n";
            $signedHeaders[] = $k;
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        $canonicalRequest = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signedHeadersStr, $payloadHash]);
        $scope = $date . '/' . self::REGION . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonicalRequest)]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', self::REGION, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeadersStr,
            $signature,
        );

        $url = 'https://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $ch = curl_init($url);
        $curlHeaders = [
            'Authorization: ' . $authHeader,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $now,
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_NOBODY => $method === 'HEAD',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('R2 request failed: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
