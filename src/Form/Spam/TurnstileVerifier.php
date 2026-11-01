<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * Cloudflare Turnstile's siteverify endpoint, over curl — hand-rolled in the
 * `CurlCfTransport` / `S3Client` spirit, no SDK and no new composer dep.
 *
 * The endpoint is a constant and never derived from input, so a tampered site
 * row cannot point verification at a host that answers "yes" to everything.
 *
 * The timeouts are short on purpose: this sits on the visitor's submit path,
 * between them pressing the button and getting an answer. A verifier that
 * takes 30 seconds is worse for the site than no verifier at all, so it gets
 * ten and then the submission proceeds on the content score alone.
 */
final class TurnstileVerifier implements CaptchaVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $secret, string $token, string $remoteIp = ''): CaptchaResult
    {
        if ($secret === '') {
            return CaptchaResult::unreachable('No Turnstile secret is configured for this site.');
        }
        // An absent token is the bot case: a direct POST to the endpoint that
        // never rendered the widget. That is a conclusive rejection, not an
        // outage, and it is the entire point of turning Turnstile on.
        if ($token === '') {
            return CaptchaResult::fail('No Turnstile token was submitted.');
        }

        $fields = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return CaptchaResult::unreachable('Could not initialise the Turnstile request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 500) {
            return CaptchaResult::unreachable(
                'Turnstile unreachable: ' . ($error !== '' ? $error : 'HTTP ' . $status),
            );
        }

        $json = json_decode((string) $raw, true);
        if (!\is_array($json)) {
            return CaptchaResult::unreachable('Turnstile returned a response that was not JSON.');
        }

        if (($json['success'] ?? false) === true) {
            return CaptchaResult::pass();
        }

        /** @var list<string> $codes */
        $codes = array_values(array_filter(
            (array) ($json['error-codes'] ?? []),
            static fn ($c): bool => \is_string($c),
        ));

        // A broken or missing SECRET is our configuration error, not the
        // visitor's failure. Rejecting them for it would take the form offline
        // for everyone the moment a key is rotated wrongly, and the symptom
        // would be "nobody can submit" with nothing explaining why.
        if (array_intersect($codes, ['invalid-input-secret', 'missing-input-secret'])) {
            return CaptchaResult::unreachable('Turnstile secret is wrong or missing: ' . implode(', ', $codes));
        }

        return CaptchaResult::fail(
            $codes === [] ? 'Turnstile rejected the challenge.' : 'Turnstile rejected the challenge: ' . implode(', ', $codes),
        );
    }
}
