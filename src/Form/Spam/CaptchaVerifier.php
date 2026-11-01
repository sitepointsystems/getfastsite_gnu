<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * One challenge verification. An interface (the CfTransport / Translator
 * pattern) so the submit path is testable against canned verdicts and the
 * tests never reach Cloudflare.
 */
interface CaptchaVerifier
{
    public function verify(string $secret, string $token, string $remoteIp = ''): CaptchaResult;
}
