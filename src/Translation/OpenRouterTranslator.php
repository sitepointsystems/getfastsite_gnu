<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Translates via OpenRouter's OpenAI-compatible chat API (the open-source
 * build's AI provider — swap-in for {@see EndpointrTranslator}).
 *   POST {AI_BASE_URL:-https://openrouter.ai/api}/v1/chat/completions
 *   Authorization: Bearer {AI_API_KEY}
 *   body: {model, messages:[system,user], temperature, max_tokens, stream:true}
 *   response: text/event-stream of OpenAI-style delta frames
 * Streams for the same reason EndpointrTranslator does: a long generation
 * with no bytes on the wire trips the ~100s edge timeout in front of
 * OpenRouter, and no client-side timeout can prevent that. Same common
 * {@see Translator} contract + same AI_API_KEY / AI_BASE_URL env as
 * endpointr, so the two are a one-line provider swap at deploy.
 */
final class OpenRouterTranslator implements Translator, ParallelTranslator, StreamProgressAware
{
    /**
     * Ceiling for one streaming call — generous because the low-speed abort
     * kills dead wires in 90s and mid-stream heartbeats (onStreamProgress)
     * keep the job alive; a large page may legitimately stream for minutes.
     */
    private const STREAM_TIMEOUT_SECONDS = 1800;

    /** Re-tick the job heartbeat at most this often while streaming. */
    private const STREAM_TICK_INTERVAL_SECONDS = 15;

    /** Silent-wire abort — mirrors the single-call path's guard. */
    private const LOW_SPEED_TIME_SECONDS = 90;

    private ?\Closure $streamTick = null;

    /**
     * @param (\Closure(array<string, mixed>): array{0: int, 1: string, 2: string})|null $streamTransport
     *        HTTP seam (body) → [status, contentType, rawBody]; defaults to curl.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'openai/gpt-4o-mini',
        private readonly int $timeoutSeconds = 420,
        private readonly ?\Closure $streamTransport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function onStreamProgress(?\Closure $tick): void
    {
        $this->streamTick = $tick;
    }

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        if (!$this->isConfigured()) {
            throw new TranslationException('OpenRouter translator is not configured.', transport: true);
        }
        if (trim($content) === '') {
            return $content;
        }

        [$status, $contentType, $raw] = $this->post($this->requestBody($content, $fromLocale, $toLocale, $format));

        return $this->readResponse($status, $contentType, $raw);
    }

    public function translateAll(array $requests, int $concurrency = 4): array
    {
        if ($requests === []) {
            return [];
        }
        if (!$this->isConfigured()) {
            return array_map(
                static fn (): TranslationException => new TranslationException('OpenRouter translator is not configured.', transport: true),
                $requests,
            );
        }

        // Empty inputs never reach the wire — the single-call path returns
        // them verbatim and so must this one.
        $out = [];
        $wire = [];
        foreach ($requests as $key => $req) {
            if (trim($req['content']) === '') {
                $out[$key] = $req['content'];
                continue;
            }
            $wire[$key] = [
                'url' => ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'https://openrouter.ai/api') . '/v1/chat/completions',
                'headers' => [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                'body' => json_encode(
                    $this->requestBody($req['content'], $req['from'], $req['to'], $req['format']),
                    JSON_THROW_ON_ERROR,
                ),
            ];
        }

        foreach (CurlMultiRunner::run(
            $wire,
            $concurrency,
            max($this->timeoutSeconds, self::STREAM_TIMEOUT_SECONDS),
            self::LOW_SPEED_TIME_SECONDS,
            $this->streamTick,
        ) as $key => $r) {
            if ($r['error'] !== '') {
                $out[$key] = new TranslationException('OpenRouter request failed: ' . $r['error'], transport: true);
                continue;
            }
            try {
                $out[$key] = $this->readResponse($r['status'], $r['contentType'], $r['raw']);
            } catch (TranslationException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    /**
     * Turns one raw HTTP response into the translated text, or throws the
     * TranslationException that describes why it isn't one. Shared so the
     * single-call and batched paths can never diverge on what a 429, a
     * non-SSE 2xx, or an empty generation means.
     */
    private function readResponse(int $status, string $contentType, string $raw): string
    {
        if ($status < 200 || $status >= 300) {
            throw new TranslationException(
                sprintf('OpenRouter returned HTTP %d: %s', $status, mb_substr($raw, 0, 200)),
                rateLimited: $status === 429,
            );
        }

        // A 2xx JSON body means stream:true was ignored — read it like a
        // plain completion rather than failing.
        if (!str_contains($contentType, 'event-stream')) {
            $decoded = json_decode($raw, true);
            $translated = \is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        } else {
            $translated = SseTextStream::text($raw);
        }

        if (!\is_string($translated) || trim($translated) === '') {
            throw new TranslationException('OpenRouter returned no translation content.');
        }

        return TranslationPrompt::stripCodeFences($translated);
    }

    /** @return array<string, mixed> */
    private function requestBody(string $content, string $from, string $to, string $format): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => TranslationPrompt::system($from, $to, $format)],
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0.2,
            'max_tokens' => 16384,
            'stream' => true,
        ];
    }

    /**
     * POST and collect the SSE response as it arrives — the per-chunk
     * WRITEFUNCTION consumption is what keeps the connection non-idle
     * through any edge proxy.
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: string, 2: string} [status, contentType, rawBody]
     */
    private function post(array $body): array
    {
        if ($this->streamTransport !== null) {
            return ($this->streamTransport)($body);
        }

        $base = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'https://openrouter.ai/api';
        $raw = '';
        $tick = $this->streamTick;
        $lastTick = time();
        $ch = curl_init($base . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            // Long ceiling: mid-stream heartbeats + the low-speed abort mean
            // a slow-but-alive generation may finish instead of dying at a
            // hard per-call cap.
            CURLOPT_TIMEOUT => max($this->timeoutSeconds, self::STREAM_TIMEOUT_SECONDS),
            // Silent-wire abort: a healthy stream pushes frames continuously;
            // only a dead edge/gateway goes quiet — fail in 90s, not 420s.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$raw, &$lastTick, $tick): int {
                $raw .= $data;
                if ($tick !== null && (time() - $lastTick) >= self::STREAM_TICK_INTERVAL_SECONDS) {
                    $lastTick = time();
                    $tick();
                }

                return \strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new TranslationException('OpenRouter request failed: ' . $error, transport: true);
        }

        return [$status, $contentType, $raw];
    }

}
