<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

/**
 * One Cloudflare API v4 call. An interface (Translator pattern) so
 * CloudflarePagesService is testable against canned responses; the real
 * implementation is CurlCfTransport, pinned to api.cloudflare.com.
 */
interface CfTransport
{
    /**
     * @param array<string, mixed>|null $body JSON body for POST/PUT
     *
     * @return array{status: int, json: array<string, mixed>}
     */
    public function request(string $method, string $path, ?array $body, string $token): array;
}
