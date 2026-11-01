<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * A real MX lookup, memoised for the life of the request.
 *
 * This runs on the visitor's submit path, so it has to be cheap: one UDP
 * round-trip to the container's resolver, and the memo means a form with two
 * email fields on the same domain pays for it once.
 *
 * It FAILS OPEN. A resolver that is slow, broken or rate-limiting must never
 * turn a real enquiry into spam — an unanswered lookup is an absence of
 * evidence, not evidence of absence, and the scorer is told "has MX" so the
 * signal simply contributes nothing. The opposite choice would mean a DNS
 * blip silently sends every lead on the site to the spam folder.
 */
final class DnsMxResolver implements MxResolver
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function hasMx(string $domain): bool
    {
        $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));
        if ($domain === '') {
            return true;
        }
        if (isset($this->memo[$domain])) {
            return $this->memo[$domain];
        }

        // An IDN has to be punycode before it reaches the resolver; without
        // this a perfectly deliverable "møller.dk" address looks like a domain
        // that cannot receive mail.
        $lookup = $domain;
        if (\function_exists('idn_to_ascii') && preg_match('//u', $domain) === 1) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (\is_string($ascii) && $ascii !== '') {
                $lookup = $ascii;
            }
        }

        try {
            $found = checkdnsrr($lookup, 'MX');
            // RFC 5321 §5.1: with no MX, the A record is the implicit fallback
            // and the domain still receives mail. Treating that as a failure
            // would flag every small domain that never configured an MX.
            if (!$found) {
                $found = checkdnsrr($lookup, 'A') || checkdnsrr($lookup, 'AAAA');
            }
        } catch (\Throwable) {
            $found = true;
        }

        return $this->memo[$domain] = $found;
    }
}
