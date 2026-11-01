<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Assembles the text of a completed SSE response body. Both translators
 * stream (a long generation behind a proxy 504s without bytes flowing —
 * streaming resets the proxy's idle timeout), and the frame shape depends
 * on which upstream provider the gateway resolved, so this accepts the
 * formats in the wild: OpenAI-style deltas (OpenAI, OpenRouter, and what
 * endpointr proxies for them), Anthropic events, and the Claude Code CLI's
 * stream-json lines (endpointr's claude-cli provider relays them verbatim
 * as `data:` frames — assistant messages carry content blocks, and the
 * terminal `result` object carries the complete reply, which wins over the
 * concatenated blocks so the text is never doubled). Comment lines
 * (": OPENROUTER PROCESSING" keepalives, heartbeats) are skipped per the
 * SSE spec.
 *
 * Fails loudly on an SSE error frame and on max-tokens truncation — a
 * truncated translation must never be stored, or the sanitizer would
 * silently "repair" the unbalanced HTML into a half-translated page.
 */
final class SseTextStream
{
    /** @throws TranslationException on an error frame or a truncated generation */
    public static function text(string $raw): string
    {
        $out = '';
        $cliResult = null;
        $cliText = '';

        foreach (preg_split('/\r?\n\r?\n/', $raw) ?: [] as $block) {
            $event = '';
            $data = [];
            foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5), ' ');
                }
            }
            if ($data === []) {
                continue;
            }
            $payload = implode("\n", $data);
            if ($payload === '[DONE]') {
                break;
            }

            $frame = json_decode($payload, true);
            if (!\is_array($frame)) {
                continue;
            }

            if ($event === 'error' || ($frame['type'] ?? '') === 'error' || isset($frame['error'])) {
                $message = self::errorMessage($frame);
                // The rate-limit marker may sit in the error TYPE (e.g.
                // Anthropic's rate_limit_error), not the message — check the
                // whole frame.
                $rateLimited = TranslationException::looksRateLimited($message)
                    || TranslationException::looksRateLimited((string) json_encode($frame, JSON_UNESCAPED_SLASHES));
                throw new TranslationException('stream error: ' . $message, rateLimited: $rateLimited);
            }

            // OpenAI-style: {choices: [{delta: {content}, finish_reason}]}
            if (isset($frame['choices'][0]) && \is_array($frame['choices'][0])) {
                $choice = $frame['choices'][0];
                $delta = $choice['delta']['content'] ?? null;
                if (\is_string($delta)) {
                    $out .= $delta;
                }
                if (($choice['finish_reason'] ?? null) === 'length') {
                    throw new TranslationException('stream truncated: the model hit max_tokens before finishing.');
                }
                continue;
            }

            // Anthropic-style: content_block_delta carries text; message_delta carries the stop reason.
            if (($frame['type'] ?? '') === 'content_block_delta' && \is_string($frame['delta']['text'] ?? null)) {
                $out .= $frame['delta']['text'];
                continue;
            }
            if (($frame['type'] ?? '') === 'message_delta' && ($frame['delta']['stop_reason'] ?? null) === 'max_tokens') {
                throw new TranslationException('stream truncated: the model hit max_tokens before finishing.');
            }

            // Claude-CLI stream-json: the terminal result is authoritative.
            if (($frame['type'] ?? '') === 'result') {
                if (($frame['is_error'] ?? false) === true) {
                    $detail = \is_string($frame['result'] ?? null) && $frame['result'] !== ''
                        ? $frame['result']
                        : (string) ($frame['subtype'] ?? 'CLI run failed');
                    throw new TranslationException(
                        'stream error: ' . mb_substr($detail, 0, 200),
                        rateLimited: TranslationException::looksRateLimited($detail),
                    );
                }
                if (\is_string($frame['result'] ?? null)) {
                    $cliResult = $frame['result'];
                }
                continue;
            }
            if (($frame['type'] ?? '') === 'assistant') {
                foreach ((array) ($frame['message']['content'] ?? []) as $block) {
                    if (\is_array($block) && ($block['type'] ?? '') === 'text' && \is_string($block['text'] ?? null)) {
                        $cliText .= $block['text'];
                    }
                }
            }
        }

        return $out !== '' ? $out : ($cliResult ?? $cliText);
    }

    /** @param array<string, mixed> $frame */
    private static function errorMessage(array $frame): string
    {
        $error = $frame['error'] ?? $frame;
        if (\is_string($error)) {
            return $error;
        }
        if (\is_array($error) && \is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return mb_substr((string) json_encode($frame, JSON_UNESCAPED_SLASHES), 0, 200);
    }
}
