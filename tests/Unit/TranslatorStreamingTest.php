<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Translation\EndpointrTranslator;
use Fastsite\Translation\OpenRouterTranslator;
use Fastsite\Translation\SseTextStream;
use Fastsite\Translation\TranslationException;
use PHPUnit\Framework\TestCase;

/**
 * Translators stream so a long generation can't sit silent past a proxy's
 * idle timeout (the production failure: endpointr's nginx 504ing on large
 * bodies). These pin the SSE assembly for both frame dialects, the
 * fallback to /v1/ai/chat when the provider can't stream, and the loud
 * failure on truncation — a half-translated body must never be stored.
 */
final class TranslatorStreamingTest extends TestCase
{
    private static function sse(string ...$dataPayloads): string
    {
        $out = ": OPENROUTER PROCESSING\n\n";
        foreach ($dataPayloads as $payload) {
            $out .= 'data: ' . $payload . "\n\n";
        }

        return $out . "data: [DONE]\n\n";
    }

    public function testAssemblesOpenAiDeltaFrames(): void
    {
        $raw = self::sse(
            '{"choices":[{"delta":{"role":"assistant"}}]}',
            '{"choices":[{"delta":{"content":"Hej "}}]}',
            '{"choices":[{"delta":{"content":"verden"}}]}',
            '{"choices":[{"delta":{},"finish_reason":"stop"}]}',
        );

        self::assertSame('Hej verden', SseTextStream::text($raw));
    }

    public function testAssemblesAnthropicFrames(): void
    {
        $raw = "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hej \"}}\n\n"
            . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"verden\"}}\n\n"
            . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n";

        self::assertSame('Hej verden', SseTextStream::text($raw));
    }

    public function testAssemblesClaudeCliFrames(): void
    {
        // endpointr's claude-cli provider relays the Claude Code CLI's
        // stream-json NDJSON lines as data: frames, plus daemon control
        // events. The terminal result carries the complete reply and must
        // win over the concatenated assistant blocks (never doubled).
        $raw = "data: {\"type\":\"system\",\"subtype\":\"init\",\"model\":\"claude\"}\n\n"
            . ": heartbeat\n\n"
            . "data: {\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"Hej verden\"}]}}\n\n"
            . "data: {\"type\":\"result\",\"subtype\":\"success\",\"result\":\"Hej verden\",\"usage\":{\"output_tokens\":3}}\n\n"
            . "event: done\ndata: {\"ok\":true}\n\n";

        self::assertSame('Hej verden', SseTextStream::text($raw));
    }

    public function testAssemblesClaudeCliAssistantBlocksWithoutResult(): void
    {
        $raw = "data: {\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"Hej \"}]}}\n\n"
            . "data: {\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"verden\"}]}}\n\n";

        self::assertSame('Hej verden', SseTextStream::text($raw));
    }

    public function testThrowsOnClaudeCliErrorResult(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('execution failed');

        SseTextStream::text(
            "data: {\"type\":\"result\",\"subtype\":\"error_during_execution\",\"is_error\":true,\"result\":\"execution failed\"}\n\n",
        );
    }

    public function testHandlesCrlfFrames(): void
    {
        $raw = "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\r\n\r\ndata: [DONE]\r\n\r\n";

        self::assertSame('ok', SseTextStream::text($raw));
    }

    public function testThrowsOnErrorFrame(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('rate limited');

        SseTextStream::text(self::sse('{"error":{"message":"rate limited","code":429}}'));
    }

    public function testThrowsOnMaxTokensTruncation(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('truncated');

        SseTextStream::text(self::sse(
            '{"choices":[{"delta":{"content":"partial"}}]}',
            '{"choices":[{"delta":{},"finish_reason":"length"}]}',
        ));
    }

    public function testThrowsOnAnthropicMaxTokens(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('truncated');

        SseTextStream::text(
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"partial\"}}\n\n"
            . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"max_tokens\"}}\n\n",
        );
    }

    public function testEndpointrStreamsAndStripsFences(): void
    {
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: fn (string $path): array => match ($path) {
                '/v1/token' => [200, ['data' => ['token' => 'jwt-1', 'expires_in' => 3600]]],
                default => self::fail('unexpected JSON call to ' . $path),
            },
            streamTransport: function (string $path, array $body, string $bearer): array {
                self::assertSame('/v1/ai/stream', $path);
                self::assertSame('jwt-1', $bearer);
                self::assertArrayHasKey('system', $body);

                return [200, 'text/event-stream', self::sse(
                    '{"choices":[{"delta":{"content":"```html\nHej"}}]}',
                    '{"choices":[{"delta":{"content":" verden\n```"}}]}',
                )];
            },
        );

        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
    }

    public function testEndpointrReExchangesTokenOn401Stream(): void
    {
        $tokenCalls = 0;
        $streamCalls = 0;
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: function (string $path) use (&$tokenCalls): array {
                self::assertSame('/v1/token', $path);
                ++$tokenCalls;

                return [200, ['data' => ['token' => 'jwt-' . $tokenCalls, 'expires_in' => 3600]]];
            },
            streamTransport: function (string $path, array $body, string $bearer) use (&$streamCalls): array {
                ++$streamCalls;
                if ($streamCalls === 1) {
                    return [401, 'application/json', '{"success":false,"error":"expired"}'];
                }
                self::assertSame('jwt-2', $bearer);

                return [200, 'text/event-stream', self::sse('{"choices":[{"delta":{"content":"Hej"}}]}')];
            },
        );

        self::assertSame('Hej', $translator->translate('Hello', 'en', 'da'));
        self::assertSame(2, $tokenCalls);
    }

    public function testEndpointrFallsBackToChatWhenProviderCannotStream(): void
    {
        $streamCalls = 0;
        $chatCalls = 0;
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: function (string $path) use (&$chatCalls): array {
                if ($path === '/v1/token') {
                    return [200, ['data' => ['token' => 'jwt', 'expires_in' => 3600]]];
                }
                self::assertSame('/v1/ai/chat', $path);
                ++$chatCalls;

                return [200, ['data' => ['content' => 'Hej verden']]];
            },
            streamTransport: function () use (&$streamCalls): array {
                ++$streamCalls;

                return [501, 'application/json', '{"success":false,"error":"streaming not supported"}'];
            },
        );

        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
        // The instance remembers: the second call goes straight to chat.
        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
        self::assertSame(1, $streamCalls);
        self::assertSame(2, $chatCalls);
    }

    public function testEndpointrFallsBackToChatOnUnrecognizedFrames(): void
    {
        // If the gateway ever streams a dialect SseTextStream doesn't know,
        // the run must degrade to the pre-streaming chat path — not fail.
        $streamCalls = 0;
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: fn (string $path): array => $path === '/v1/token'
                ? [200, ['data' => ['token' => 'jwt', 'expires_in' => 3600]]]
                : [200, ['data' => ['content' => 'Hej verden']]],
            streamTransport: function () use (&$streamCalls): array {
                ++$streamCalls;

                return [200, 'text/event-stream', "data: {\"unknown\":\"dialect\"}\n\ndata: [DONE]\n\n"];
            },
        );

        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
        self::assertSame(1, $streamCalls);
    }

    public function testEndpointrJsonOnlyTestDoubleSkipsStreaming(): void
    {
        // Back-compat: a transport-only construction (no stream seam) must
        // never attempt a real curl stream.
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: fn (string $path): array => $path === '/v1/token'
                ? [200, ['data' => ['token' => 'jwt', 'expires_in' => 3600]]]
                : [200, ['data' => ['content' => 'Hej']]],
        );

        self::assertSame('Hej', $translator->translate('Hello', 'en', 'da'));
    }

    public function testEndpointrSurfacesMidStreamError(): void
    {
        $translator = new EndpointrTranslator(
            'https://gw.test',
            'key',
            transport: fn (): array => [200, ['data' => ['token' => 'jwt', 'expires_in' => 3600]]],
            streamTransport: fn (): array => [200, 'text/event-stream', self::sse(
                '{"choices":[{"delta":{"content":"Hej"}}]}',
                '{"error":{"message":"upstream exploded"}}',
            )],
        );

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('upstream exploded');
        $translator->translate('Hello', 'en', 'da');
    }

    public function testOpenRouterStreams(): void
    {
        $translator = new OpenRouterTranslator(
            'https://or.test',
            'sk-key',
            streamTransport: function (array $body): array {
                self::assertTrue($body['stream']);

                return [200, 'text/event-stream; charset=utf-8', self::sse(
                    '{"choices":[{"delta":{"content":"Hej "}}]}',
                    '{"choices":[{"delta":{"content":"verden"}}]}',
                )];
            },
        );

        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
    }

    public function testOpenRouterAcceptsPlainJsonWhenStreamIgnored(): void
    {
        $translator = new OpenRouterTranslator(
            'https://or.test',
            'sk-key',
            streamTransport: fn (): array => [200, 'application/json', '{"choices":[{"message":{"content":"Hej verden"}}]}'],
        );

        self::assertSame('Hej verden', $translator->translate('Hello world', 'en', 'da'));
    }

    public function testOpenRouterThrowsOnHttpError(): void
    {
        $translator = new OpenRouterTranslator(
            'https://or.test',
            'sk-key',
            streamTransport: fn (): array => [402, 'application/json', '{"error":{"message":"insufficient credits"}}'],
        );

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('HTTP 402');
        $translator->translate('Hello world', 'en', 'da');
    }
}
