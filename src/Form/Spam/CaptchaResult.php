<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * The outcome of a challenge verification.
 *
 * `conclusive` is the field that matters and the reason this is a DTO rather
 * than a bool. There are three outcomes, not two: the challenge passed, the
 * challenge was rejected, or we never got an answer out of the verifier at
 * all. Collapsing the third into either of the first two is how a captcha
 * either stops working silently or starts eating real submissions silently.
 */
final readonly class CaptchaResult
{
    private function __construct(
        public bool $passed,
        public bool $conclusive,
        public string $error,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, true, '');
    }

    public static function fail(string $error): self
    {
        return new self(false, true, $error);
    }

    /**
     * No answer from the verifier — a timeout, a DNS failure, an outage.
     *
     * Treated as a PASS by the caller, deliberately. A visitor who filled in a
     * form correctly must not be turned away because Cloudflare is having a
     * bad afternoon, and a bot cannot cause this state: it is our outbound
     * call that failed, not anything the submitter controls. The content
     * scorer still runs, so the submission is not unguarded — it has simply
     * lost one of its two guards.
     */
    public static function unreachable(string $error): self
    {
        return new self(true, false, $error);
    }
}
