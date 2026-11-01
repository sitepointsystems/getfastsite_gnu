<?php

declare(strict_types=1);

namespace Fastsite\Seo;

/**
 * Rewrites an SEO title/description pair to fit the Google/Yoast length
 * envelope ({@see SeoLimits}) using the site's OpenAI-compatible AI gateway
 * (the same OpenRouter credential the other clients use). Fail-safe: any
 * error returns null and the caller falls back to a word-boundary truncate —
 * but the reason is kept in {@see lastError()} so the caller can log and
 * report it instead of the failure vanishing without a trace.
 *
 * That reason has to be ACTIONABLE, because it is the only thing the user
 * ever sees: the app log lives inside the container. A gateway that answers
 * 2xx without an answer is therefore diagnosed rather than dismissed — see
 * {@see diagnose()} for what "no message content" was hiding.
 */
final class SeoRewriter
{
    /**
     * Output budget for ONE rewrite. The answer itself is ~60 tokens of JSON —
     * but on a reasoning model the thinking tokens are drawn from this SAME
     * budget, and at the old 500 the model could spend the whole allowance
     * before writing a single character of the answer. The gateway then
     * returns a choice with `content: null` and finish_reason `length`, which
     * is precisely what surfaced as "gateway response carried no message
     * content". Only about half a run looked broken because the other half
     * were OVER-long rows: those get rescued by word-boundary truncation and
     * report "fixed", so the same failing AI call was invisible on them.
     */
    private const MAX_TOKENS = 1500;

    /**
     * Second, larger attempt when the first answer was cut off (see
     * {@see worthRetryingLarger}). Kept at 4000 because that is under the
     * 4096-token output ceiling of the smallest models on the catalogue — a
     * budget the model rejects would trade one failure for another.
     */
    private const MAX_TOKENS_RETRY = 4000;

    /**
     * Only start a retry when this fraction of the per-call timeout is still
     * unspent. The dashboard's fix dialog runs a row INSIDE one HTTP request
     * behind a 120s nginx read timeout — two full-length calls must never
     * outlive it.
     */
    private const RETRY_TIME_FRACTION = 0.5;

    private ?string $lastError = null;
    private ?\Closure $onProgress = null;

    /**
     * @param (\Closure(array<string, mixed>, ?\Closure): array<string, mixed>)|null $transport
     *        alternate client (chat body, progress tick) → decoded response —
     *        the endpointr gateway adapter in production, a canned response in
     *        tests; defaults to curl against baseUrl/apiKey/model. The second
     *        arg is the {@see onProgress} tick (null when unset) — extra args
     *        are ignored by PHP closures, so one-param transports keep working.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 60,
        private readonly ?\Closure $transport = null,
    ) {
    }

    /**
     * Live-progress hook: called with the CUMULATIVE response bytes while the
     * AI answer streams in. Only transports that stream (the endpointr
     * gateway adapter) ever tick it — the built-in non-streaming curl path
     * doesn't, and callers must treat no-ticks as normal.
     *
     * @param (\Closure(int): void)|null $tick
     */
    public function onProgress(?\Closure $tick): void
    {
        $this->onProgress = $tick;
    }

    public function isConfigured(): bool
    {
        // A transport IS the client (it carries its own credentials).
        return $this->transport !== null || ($this->apiKey !== '' && $this->model !== '');
    }

    /** Why the last rewrite() returned null (null when it succeeded). */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Returns ['title' => …, 'description' => …] fitting the limits, or null
     * when unconfigured / the call fails / the output cannot be parsed.
     *
     * @return array{title: string, description: string}|null
     */
    public function rewrite(string $title, string $description, string $bodyText): ?array
    {
        $this->lastError = null;
        if (!$this->isConfigured()) {
            $this->lastError = 'no AI configured';

            return null;
        }
        if (trim($title) === '' && trim($description) === '') {
            $this->lastError = 'nothing to rewrite (empty title and description)';

            return null;
        }

        $startedAt = microtime(true);
        try {
            $response = $this->post($this->requestBody($title, $description, $bodyText, self::MAX_TOKENS));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null; // fail-safe — the caller falls back to truncation
        }

        $text = $this->assistantText($response);
        $parsed = $this->fromText($text, $response);

        // An answer cut off at the token limit is the ONE failure a second,
        // larger call reliably fixes — so spend it, rather than handing the
        // caller a machine-truncated title. Skipped once the first call has
        // eaten half the time budget (see RETRY_TIME_FRACTION).
        if ($parsed === null
            && $this->worthRetryingLarger($text, $response)
            && (microtime(true) - $startedAt) < $this->timeoutSeconds * self::RETRY_TIME_FRACTION
        ) {
            try {
                $response = $this->post($this->requestBody($title, $description, $bodyText, self::MAX_TOKENS_RETRY));
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();

                return null;
            }
            $parsed = $this->fromText($this->assistantText($response), $response);
        }

        return $parsed;
    }

    /**
     * The rewritten pair carried by one response, or null with {@see lastError}
     * explaining what came back instead.
     *
     * @param array<string, mixed> $response
     * @return array{title: string, description: string}|null
     */
    private function fromText(string $text, array $response): ?array
    {
        if ($text === '') {
            $this->lastError = 'the model returned no text — ' . $this->diagnose($response);

            return null;
        }

        $decoded = $this->extractJson($text);
        $newTitle = trim((string) preg_replace('/\s+/', ' ', (string) ($decoded['title'] ?? '')));
        $newDescription = trim((string) preg_replace('/\s+/', ' ', (string) ($decoded['description'] ?? '')));
        if ($newTitle === '' && $newDescription === '') {
            $this->lastError = 'could not parse a title/description from the model output'
                . ($this->cutOff($response) ? ' (cut off at the token limit)' : '')
                . ': ' . mb_substr($text, 0, 120);

            return null;
        }
        $this->lastError = null;

        return ['title' => $newTitle, 'description' => $newDescription];
    }

    /**
     * The assistant text of an OpenAI-shaped response. `content` is normally a
     * plain string, but providers relayed through OpenRouter also answer in
     * the multi-part array form ([{type:"text",text:"…"}]) — reading only the
     * string case turned a perfectly good answer into "no message content".
     *
     * @param array<string, mixed> $response
     */
    private function assistantText(array $response): string
    {
        $message = $response['choices'][0]['message'] ?? null;
        if (!\is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? null;
        if (\is_string($content)) {
            return trim($content);
        }
        if (\is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (\is_string($part)) {
                    $text .= $part;
                } elseif (\is_array($part) && \is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            return trim($text);
        }

        return '';
    }

    /**
     * Why a response carried no usable answer, phrased so the user can act on
     * it: the provider's own error, or the finish reason plus where the token
     * budget actually went. "gateway response carried no message content" told
     * nobody anything — least of all that the model never got to answer.
     *
     * @param array<string, mixed> $response
     */
    private function diagnose(array $response): string
    {
        // Some gateways report provider failures in a 200 body rather than an
        // HTTP status, so this is the real error a lot of the time.
        $error = $response['error'] ?? null;
        if (\is_array($error) || \is_string($error)) {
            $message = \is_array($error) ? ($error['message'] ?? $error) : $error;

            return 'the gateway reported: ' . $this->snippet(\is_string($message) ? $message : (string) json_encode($message));
        }
        if (!isset($response['choices'][0])) {
            return 'the response carried no choices: ' . $this->snippet((string) json_encode($response));
        }

        /** @var array<string, mixed> $choice */
        $choice = $response['choices'][0];
        $finish = (string) ($choice['finish_reason'] ?? $choice['native_finish_reason'] ?? '');
        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $reasoning = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);

        if ($reasoning > 0) {
            return sprintf(
                'the model spent its whole answer budget thinking (%d reasoning tokens of %d) and never wrote the answer — choose a non-reasoning model for this site\'s AI key',
                $reasoning,
                (int) ($usage['completion_tokens'] ?? $reasoning),
            );
        }
        if ($this->cutOff($response)) {
            return 'the answer was cut off at the token limit before any text arrived';
        }
        if ($finish === 'content_filter') {
            return 'the provider\'s content filter blocked the answer';
        }

        return 'finish_reason=' . ($finish !== '' ? $finish : 'none')
            . ', message=' . $this->snippet((string) json_encode($choice['message'] ?? $choice));
    }

    /**
     * The provider says this answer hit the output-token ceiling.
     *
     * @param array<string, mixed> $response
     */
    private function cutOff(array $response): bool
    {
        $choice = $response['choices'][0] ?? null;
        if (!\is_array($choice)) {
            return false;
        }
        $finish = strtolower((string) ($choice['finish_reason'] ?? ''));
        $native = strtolower((string) ($choice['native_finish_reason'] ?? ''));

        return \in_array('length', [$finish, $native], true) || \in_array('max_tokens', [$finish, $native], true);
    }

    /**
     * Whether a second call with a bigger budget can plausibly succeed: the
     * answer was truncated, or a reasoning model burned the budget and left no
     * text at all. Never on a gateway error — that repeats identically.
     *
     * @param array<string, mixed> $response
     */
    private function worthRetryingLarger(string $text, array $response): bool
    {
        if (isset($response['error'])) {
            return false;
        }
        if ($this->cutOff($response)) {
            return true;
        }

        return $text === '' && (int) ($response['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $title, string $description, string $bodyText, int $maxTokens): array
    {
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' =>
                    'Current SEO title: ' . ($title !== '' ? $title : '(empty — write one)') . "\n"
                    . 'Current meta description: ' . ($description !== '' ? $description : '(empty — write one)') . "\n\n"
                    . 'Article text (for context, may be truncated): ' . mb_substr(trim($bodyText), 0, 2000)],
            ],
            'temperature' => 0.3,
            'max_tokens' => $maxTokens,
        ];

        // Rewriting two short strings needs no chain of thought, and thinking
        // tokens come out of max_tokens — the very thing that starved the
        // answer. OpenRouter ignores this for models that can't turn thinking
        // off (the retry's larger budget covers those). Sent only when the
        // gateway IS OpenRouter, so a custom OpenAI-compatible base URL never
        // has to tolerate a field it doesn't know; a transport that adapts the
        // body to another API (endpointr) simply ignores the key.
        if (str_contains($this->base(), 'openrouter.ai')) {
            $body['reasoning'] = ['enabled' => false];
        }

        return $body;
    }

    private function systemPrompt(): string
    {
        return "You are an SEO copywriter. Rewrite the given SEO title and meta description so they fit Google's search snippet, exactly as Yoast enforces:\n"
            . '- title: at most ' . SeoLimits::TITLE_MAX . ' characters, ideally ' . SeoLimits::TITLE_MIN . '–' . SeoLimits::TITLE_MAX . ". Keep the main keyword near the front; keep the meaning; no clickbait.\n"
            . '- description: ' . SeoLimits::DESC_MIN . '–' . SeoLimits::DESC_MAX . " characters, one or two natural sentences that summarize the page and invite the click.\n"
            . "- If a value is marked empty, WRITE one from the article text. Never return an empty string for either field.\n"
            . "- Same language as the input. Count characters carefully — going over the limit is a failure.\n"
            . "- Answer immediately, with no preamble and no explanation.\n"
            . '- Return ONLY minified JSON, exactly: {"title":"…","description":"…"}';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/is', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $decoded = json_decode($text, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($body, $this->onProgress);
        }

        $ch = curl_init($this->base() . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('SEO-rewrite gateway request failed: ' . $error);
        }
        // The body says WHY (bad key, no credits, unknown model id) — a bare
        // status code sends the user hunting through logs that live inside
        // the container.
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('SEO-rewrite gateway returned HTTP %d: %s', $status, $this->snippet((string) $raw)));
        }
        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('SEO-rewrite gateway returned non-JSON: ' . $this->snippet((string) $raw));
        }

        return $decoded;
    }

    /** The AI gateway this rewriter talks to (OpenRouter unless overridden). */
    private function base(): string
    {
        return $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'https://openrouter.ai/api';
    }

    /** A short single-line excerpt for an error message. */
    private function snippet(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 200);
    }
}
