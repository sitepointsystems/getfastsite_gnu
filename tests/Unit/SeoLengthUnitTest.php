<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Media\AltTextGenerator;
use Fastsite\Seo\SeoLimits;
use Fastsite\Seo\SeoRewriter;
use PHPUnit\Framework\TestCase;

/**
 * The Google/Yoast snippet envelope: word-boundary truncation, the AI
 * rewriter's JSON handling, and the alt-text generator's normalization —
 * all through the transport seam, no network.
 */
final class SeoLengthUnitTest extends TestCase
{
    public function testTruncateCutsAtWordBoundaryAndKeepsShortInput(): void
    {
        self::assertSame('Short title', SeoLimits::truncate('Short title', SeoLimits::TITLE_MAX));

        $long = 'The complete and utterly exhaustive guide to building extremely fast static websites in 2026';
        $cut = SeoLimits::truncate($long, SeoLimits::TITLE_MAX);
        self::assertLessThanOrEqual(SeoLimits::TITLE_MAX, mb_strlen($cut));
        self::assertStringStartsWith('The complete and utterly exhaustive guide', $cut);
        self::assertFalse(str_ends_with($cut, ' '), 'No trailing space.');

        // Whitespace normalization happens even under the limit.
        self::assertSame('a b', SeoLimits::truncate("a\n  b", 10));
    }

    public function testRewriterParsesJsonAndFailsSafe(): void
    {
        $rewriter = new SeoRewriter('http://x', 'key', 'model', 5, static fn (array $body): array => [
            'choices' => [['message' => ['content' => "```json\n{\"title\":\"Fast static sites, explained\",\"description\":\"How fastsite turns content into fully static pages that score 100 on PageSpeed, with zero JavaScript shipped and instant loads on any device.\"}\n```"]]],
        ]);
        $out = $rewriter->rewrite('A very long old title', 'old description', 'body text');
        self::assertNotNull($out);
        self::assertSame('Fast static sites, explained', $out['title']);
        self::assertLessThanOrEqual(SeoLimits::DESC_MAX, mb_strlen($out['description']));

        // Transport failure → null (caller falls back to truncation) — and
        // the reason is kept, never swallowed invisibly.
        $failing = new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => throw new \RuntimeException('down'));
        self::assertNull($failing->rewrite('t', 'd', 'b'));
        self::assertSame('down', $failing->lastError());

        // Unparseable output records why.
        $garbled = new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => [
            'choices' => [['message' => ['content' => 'Sure! Here are some ideas…']]],
        ]);
        self::assertNull($garbled->rewrite('t', 'd', 'b'));
        self::assertStringContainsString('could not parse', (string) $garbled->lastError());

        // Unconfigured → null without calling anything.
        $unconfigured = new SeoRewriter('', '', '');
        self::assertFalse($unconfigured->isConfigured());
        self::assertNull($unconfigured->rewrite('t', 'd', 'b'));

        // A transport IS a client: configured even with no key/model of its
        // own (the endpointr gateway adapter rides in this way).
        self::assertTrue((new SeoRewriter('', '', '', 5, static fn (): array => []))->isConfigured());
    }

    /**
     * The "gateway response carried no message content" bug: a reasoning model
     * spends the whole max_tokens budget thinking and the choice comes back
     * with content:null + finish_reason length. The rewrite must not die there
     * — reasoning is turned off on OpenRouter, and a truncated answer earns one
     * larger retry.
     */
    public function testStarvedReasoningAnswerRetriesWithABiggerBudget(): void
    {
        $bodies = [];
        $rewriter = new SeoRewriter('', 'key', 'model', 60, static function (array $body) use (&$bodies): array {
            $bodies[] = $body;
            if (\count($bodies) === 1) {
                return [
                    'choices' => [['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => null, 'reasoning' => 'Let me think about the keyword…']]],
                    'usage' => ['completion_tokens' => 1500, 'completion_tokens_details' => ['reasoning_tokens' => 1500]],
                ];
            }

            return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"title":"IP addresses explained simply","description":"What an IP address is, how the public and private kinds differ, and why every device on a network needs one to be reachable."}']]]];
        });

        $out = $rewriter->rewrite('IP addresses', '', 'body text about networking');
        self::assertNotNull($out, 'A budget-starved first answer must not end the rewrite.');
        self::assertSame('IP addresses explained simply', $out['title']);
        self::assertNull($rewriter->lastError());

        self::assertCount(2, $bodies);
        self::assertSame(1500, $bodies[0]['max_tokens']);
        self::assertGreaterThan(1500, $bodies[1]['max_tokens'], 'The retry raises the ceiling.');
        // Thinking is what ate the budget — switch it off where the gateway
        // understands the knob (the default gateway is OpenRouter).
        self::assertSame(['enabled' => false], $bodies[0]['reasoning']);
        // An empty field is asked for explicitly, not sent as a blank line.
        self::assertStringContainsString('(empty — write one)', (string) $bodies[0]['messages'][1]['content']);
    }

    public function testUnanswerableResponsesExplainThemselves(): void
    {
        // Still starved after the retry → the message names the cause instead
        // of the useless "carried no message content".
        $starved = new SeoRewriter('', 'key', 'model', 60, static fn (): array => [
            'choices' => [['finish_reason' => 'length', 'message' => ['content' => null]]],
            'usage' => ['completion_tokens' => 4000, 'completion_tokens_details' => ['reasoning_tokens' => 4000]],
        ]);
        self::assertNull($starved->rewrite('t', 'd', 'b'));
        self::assertStringContainsString('reasoning tokens', (string) $starved->lastError());
        self::assertStringContainsString('non-reasoning model', (string) $starved->lastError());

        // A 200 body carrying a provider error reports THAT, and never burns a
        // retry on a call that will fail identically.
        $calls = 0;
        $errored = new SeoRewriter('', 'key', 'model', 60, static function () use (&$calls): array {
            $calls++;

            return ['error' => ['code' => 402, 'message' => 'Insufficient credits']];
        });
        self::assertNull($errored->rewrite('t', 'd', 'b'));
        self::assertSame(1, $calls);
        self::assertStringContainsString('Insufficient credits', (string) $errored->lastError());
    }

    public function testMultiPartContentIsRead(): void
    {
        // Some providers relayed through OpenRouter answer with content parts
        // rather than a bare string — that is an answer, not a failure.
        $rewriter = new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => [
            'choices' => [['message' => ['content' => [
                ['type' => 'text', 'text' => '{"title":"IP addresses explained simply",'],
                ['type' => 'text', 'text' => '"description":"What an IP address is, how public and private ones differ, and why every device on a network needs one to be reachable at all."}'],
            ]]]],
        ]);
        $out = $rewriter->rewrite('IP addresses', 'short', 'body');
        self::assertNotNull($out);
        self::assertSame('IP addresses explained simply', $out['title']);

        // A non-OpenRouter base URL is never sent OpenRouter's reasoning knob.
        $captured = null;
        $custom = new SeoRewriter('https://ai.internal/api', 'key', 'model', 5, static function (array $body) use (&$captured): array {
            $captured = $body;

            return ['choices' => [['message' => ['content' => '{"title":"x","description":"y"}']]]];
        });
        $custom->rewrite('t', 'd', 'b');
        self::assertArrayNotHasKey('reasoning', (array) $captured);
    }

    public function testEndpointrChatClientBacksTheRewriter(): void
    {
        // Stub gateway: token exchange, then /v1/ai/chat answering with the
        // rewrite JSON — through SeoRewriter's transport seam end-to-end.
        // (JSON-only test double → the client skips streaming, like the
        // translator's seam does.)
        $calls = [];
        $client = new \Fastsite\Ai\EndpointrChatClient('http://gw', 'ep_key', 5, static function (string $path, array $body, ?string $bearer) use (&$calls): array {
            $calls[] = [$path, $body, $bearer];
            if ($path === '/v1/token') {
                return [200, ['data' => ['token' => 'jwt-1', 'expires_in' => 3600]]];
            }

            return [200, ['data' => ['content' => '{"title":"Fast static sites, explained","description":"How fastsite turns content into fully static pages that score 100 on PageSpeed, with zero JavaScript shipped and instant loads on any device."}']]];
        });

        $rewriter = new SeoRewriter('', '', '', 5, $client->seoRewriterTransport());
        self::assertTrue($rewriter->isConfigured());

        $out = $rewriter->rewrite('A very long old title', 'old description', 'body text');
        self::assertNotNull($out);
        self::assertSame('Fast static sites, explained', $out['title']);

        // The chat call went out authenticated and carried the prompt parts.
        self::assertSame('/v1/token', $calls[0][0]);
        self::assertSame('/v1/ai/chat', $calls[1][0]);
        self::assertSame('jwt-1', $calls[1][2]);
        self::assertStringContainsString('SEO copywriter', (string) $calls[1][1]['system']);
        self::assertStringContainsString('A very long old title', (string) $calls[1][1]['prompt']);

        // A gateway failure surfaces as lastError, not silence.
        $down = new \Fastsite\Ai\EndpointrChatClient('http://gw', 'ep_key', 5, static fn (): array => [503, ['error' => 'upstream down']]);
        $failing = new SeoRewriter('', '', '', 5, $down->seoRewriterTransport());
        self::assertNull($failing->rewrite('t', 'd', 'b'));
        self::assertStringContainsString('HTTP 503', (string) $failing->lastError());
    }

    public function testProgressTickReachesTheStreamTransport(): void
    {
        // The dialog's live bar: SeoRewriter.onProgress → transport 2nd arg →
        // EndpointrChatClient.chat → the stream seam's 4th arg. Cumulative
        // bytes reported by the seam must reach the original tick.
        $seen = [];
        $client = new \Fastsite\Ai\EndpointrChatClient(
            'http://gw',
            'ep_key',
            5,
            static fn (): array => [200, ['data' => ['token' => 'jwt-1', 'expires_in' => 3600]]],
            static function (string $path, array $body, string $bearer, ?\Closure $progress = null): array {
                $progress?->__invoke(1024);
                $progress?->__invoke(2048);
                $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => '{"title":"Streamed title fits","description":"A streamed description that is long enough to satisfy the Google minimum for meta descriptions across the snippet, well over the lower bound."}']]]]) . "\n\n";

                return [200, 'text/event-stream', $sse];
            },
        );

        $rewriter = new SeoRewriter('', '', '', 5, $client->seoRewriterTransport());
        $rewriter->onProgress(static function (int $bytes) use (&$seen): void { $seen[] = $bytes; });
        $out = $rewriter->rewrite('A very long old title', 'old description', 'body');
        self::assertNotNull($out);
        self::assertSame([1024, 2048], $seen);

        // No tick set → the transport gets null and nothing explodes.
        $rewriter->onProgress(null);
        self::assertNotNull($rewriter->rewrite('A very long old title', 'old description', 'body'));
    }

    public function testEndpointrChatClientStreamsFirst(): void
    {
        $json = '{"title":"Fast static sites, explained","description":"How fastsite turns content into fully static pages that score 100 on PageSpeed, with zero JavaScript shipped and instant loads on any device."}';

        // Streaming answers: /v1/ai/chat must never be called — the idle-quiet
        // chat endpoint is exactly what times out behind proxies.
        $jsonCalls = [];
        $streamCalls = [];
        $client = new \Fastsite\Ai\EndpointrChatClient(
            'http://gw',
            'ep_key',
            5,
            static function (string $path) use (&$jsonCalls): array {
                $jsonCalls[] = $path;

                return [200, ['data' => ['token' => 'jwt-1', 'expires_in' => 3600]]];
            },
            static function (string $path, array $body, string $bearer) use (&$streamCalls, $json): array {
                $streamCalls[] = $path;
                $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => $json]]]]) . "\n\ndata: [DONE]\n\n";

                return [200, 'text/event-stream', $sse];
            },
        );
        self::assertSame($json, $client->chat('system', 'prompt'));
        self::assertSame(['/v1/token'], $jsonCalls, 'only the token exchange hits the JSON path');
        self::assertSame(['/v1/ai/stream'], $streamCalls);

        // A provider that can't stream (501) → the chat fallback delivers,
        // and the instance remembers (the next call skips the stream).
        $jsonCalls = [];
        $streamCalls = [];
        $client = new \Fastsite\Ai\EndpointrChatClient(
            'http://gw',
            'ep_key',
            5,
            static function (string $path) use (&$jsonCalls, $json): array {
                $jsonCalls[] = $path;

                return $path === '/v1/token'
                    ? [200, ['data' => ['token' => 'jwt-1', 'expires_in' => 3600]]]
                    : [200, ['data' => ['content' => $json]]];
            },
            static function (string $path) use (&$streamCalls): array {
                $streamCalls[] = $path;

                return [501, 'application/json', '{"error":"provider cannot stream"}'];
            },
        );
        self::assertSame($json, $client->chat('system', 'prompt'));
        self::assertSame($json, $client->chat('system', 'prompt'));
        self::assertSame(['/v1/ai/stream'], $streamCalls, 'the doomed stream attempt happens once, not per call');
        self::assertSame(['/v1/token', '/v1/ai/chat', '/v1/ai/chat'], $jsonCalls);
    }

    public function testAltTextGeneratorNormalizesAndFailsSafe(): void
    {
        $captured = null;
        $generator = new AltTextGenerator('http://x', 'key', 'vision-model', 5, static function (array $body) use (&$captured): array {
            $captured = $body;

            return ['choices' => [['message' => ['content' => "  \"A red bicycle leaning against a brick wall\"  \n"]]]];
        });
        $alt = $generator->generate('fake-image-bytes', 'image/webp', 'bike.webp');
        self::assertSame('A red bicycle leaning against a brick wall', $alt);
        // The image went along as a data URI and the context reached the prompt.
        self::assertIsArray($captured);
        $userContent = $captured['messages'][1]['content'];
        self::assertStringStartsWith('data:image/webp;base64,', $userContent[1]['image_url']['url']);
        self::assertStringContainsString('bike.webp', $userContent[0]['text']);

        // Failures and oversized images return null — never an exception.
        $failing = new AltTextGenerator('http://x', 'key', 'm', 5, static fn (): array => throw new \RuntimeException('down'));
        self::assertNull($failing->generate('bytes', 'image/png'));
        self::assertNull((new AltTextGenerator('', '', ''))->generate('bytes', 'image/png'));
        self::assertNull($generator->generate(str_repeat('x', 5_000_000), 'image/png'), 'Oversized payloads are skipped.');
    }

    public function testHumanizeFilenameFallback(): void
    {
        self::assertSame('Hero image v2 final', \Fastsite\Media\Service\MediaService::humanizeFilename('hero-image_v2 final.png'));
        self::assertSame('Image', \Fastsite\Media\Service\MediaService::humanizeFilename('---.jpg'));
    }
}
