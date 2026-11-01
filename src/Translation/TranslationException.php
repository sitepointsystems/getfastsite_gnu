<?php

declare(strict_types=1);

namespace Fastsite\Translation;

final class TranslationException extends \RuntimeException
{
    /**
     * $transport marks failures where the gateway never produced an HTTP
     * response at all (connect refused, silent-wire abort, total timeout,
     * failed token exchange). Item-level rejections (HTTP 4xx/5xx, empty or
     * truncated output) are NOT transport failures — one bad item says
     * nothing about the next, but two consecutive transport failures mean
     * the gateway itself is unreachable and a run should stop probing it.
     *
     * $rateLimited marks HTTP 429s and provider rate-limit/overloaded error
     * frames. Like transport, it says nothing about the item — but the
     * respectful response is to WAIT (pause + parked retry), never to brand
     * the row failed or hammer the next item.
     */
    public function __construct(
        string $message,
        public readonly bool $transport = false,
        public readonly bool $rateLimited = false,
    ) {
        parent::__construct($message);
    }

    /** Whether a provider error message describes a rate limit / overload. */
    public static function looksRateLimited(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'rate_limit') || str_contains($m, 'rate limit')
            || str_contains($m, 'too many requests') || str_contains($m, 'overloaded')
            || str_contains($m, 'quota') || str_contains($m, '429');
    }
}
