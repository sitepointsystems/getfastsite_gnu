<?php

declare(strict_types=1);

namespace Fastsite\Form\Service;

use Predis\Client;

/**
 * How often one visitor may submit one form.
 *
 * The `form` middleware group's own per-IP cap is a generic HTTP backstop
 * (60/minute). That is nowhere near tight enough for something that relays
 * mail through a customer's own mail account: a provider will suspend an
 * account for a fraction of that volume, and it is the customer's account, not
 * ours. So this is deliberately mean — a human filling in a contact form
 * submits it once, occasionally twice.
 *
 * Fails OPEN. If Redis is down, a visitor who took the trouble to write a
 * message must not be turned away; the honeypot, the validation and the
 * group's own IP cap still stand.
 */
final class SubmissionThrottle
{
    private const PER_HOUR = 8;
    private const WINDOW = 3600;

    public function __construct(private readonly Client $redis)
    {
    }

    /** True when this IP has already had its quota for this form. */
    public function exceeded(string $siteHandle, string $formHandle, string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $key = sprintf(
            'fastsite:form:%s:%s:%s:%d',
            $siteHandle,
            $formHandle,
            substr(hash('sha256', $ip), 0, 24),
            intdiv(time(), self::WINDOW),
        );

        try {
            $count = (int) $this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, self::WINDOW + 60);
            }

            return $count > self::PER_HOUR;
        } catch (\Throwable) {
            return false;
        }
    }
}
