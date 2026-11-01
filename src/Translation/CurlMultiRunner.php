<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * Runs a set of POSTs concurrently over one curl_multi pool, keeping at most
 * $concurrency in flight and starting the next as soon as a slot frees.
 *
 * Shared by {@see EndpointrTranslator} and {@see OpenRouterTranslator} so the
 * two carry ONE implementation of the fiddly parts: SSE bodies are consumed
 * per chunk (that steady consumption is what stops any proxy on the path from
 * seeing an idle upstream), the low-speed abort still kills dead wires in
 * ~90s rather than sitting out the full ceiling, and the job heartbeat ticks
 * from the poll loop so a whole batch of slow-but-alive streams can never be
 * mistaken for a dead worker.
 *
 * A transport failure is reported PER REQUEST (`error` non-empty), never
 * thrown — one dead connection in a batch of twenty must not discard the
 * nineteen that succeeded.
 */
final class CurlMultiRunner
{
    /** Re-tick the caller's heartbeat at most this often while the pool runs. */
    private const TICK_INTERVAL_SECONDS = 15;

    /** Upper bound on in-flight calls — past this the gateway, not us, is the bottleneck. */
    public const MAX_CONCURRENCY = 16;

    /**
     * @param  array<string, array{url: string, headers: list<string>, body: string}>  $requests
     * @param  ?\Closure  $tick  called (no args) at most every TICK_INTERVAL_SECONDS while requests are in flight
     * @return array<string, array{status: int, contentType: string, raw: string, error: string}> keyed like $requests
     */
    public static function run(
        array $requests,
        int $concurrency,
        int $timeoutSeconds,
        int $lowSpeedTimeSeconds,
        ?\Closure $tick = null,
    ): array {
        if ($requests === []) {
            return [];
        }

        $concurrency = max(1, min(self::MAX_CONCURRENCY, $concurrency));
        $multi = curl_multi_init();
        $queued = $requests;
        /** @var array<int, array-key> $inFlight  spl_object_id(handle) => request key */
        $inFlight = [];
        /** @var array<array-key, string> $bodies */
        $bodies = [];
        $results = [];

        $start = static function () use (&$queued, &$inFlight, &$bodies, $multi, $timeoutSeconds, $lowSpeedTimeSeconds): bool {
            $key = array_key_first($queued);
            if ($key === null) {
                return false;
            }
            $spec = $queued[$key];
            unset($queued[$key]);

            $bodies[$key] = '';
            $ch = curl_init($spec['url']);
            \assert($ch instanceof \CurlHandle);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $spec['body'],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                // Silent-wire abort: a healthy stream pushes frames
                // continuously — only a dead gateway goes quiet this long.
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $lowSpeedTimeSeconds,
                CURLOPT_HTTPHEADER => $spec['headers'],
                // Consume each chunk as it lands — see the class docblock.
                CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$bodies, $key): int {
                    $bodies[$key] .= $data;

                    return \strlen($data);
                },
            ]);
            $inFlight[spl_object_id($ch)] = $key;
            curl_multi_add_handle($multi, $ch);

            return true;
        };

        for ($i = 0; $i < $concurrency; ++$i) {
            if (!$start()) {
                break;
            }
        }

        $lastTick = time();
        do {
            curl_multi_exec($multi, $running);
            // Blocks until something happens or 1s passes; -1 means curl had
            // nothing to wait on, in which case a short sleep avoids a spin.
            if (curl_multi_select($multi, 1.0) === -1) {
                usleep(50_000);
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                \assert($ch instanceof \CurlHandle);
                $key = $inFlight[spl_object_id($ch)] ?? null;
                if ($key !== null) {
                    $results[$key] = [
                        'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                        'contentType' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                        'raw' => $bodies[$key] ?? '',
                        'error' => $info['result'] === CURLE_OK ? '' : (curl_error($ch) ?: 'curl error ' . $info['result']),
                    ];
                    unset($inFlight[spl_object_id($ch)], $bodies[$key]);
                }
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);

                // Backfill the freed slot immediately — a batch should never
                // idle a connection while work is still queued.
                $start();
            }

            if ($tick !== null && (time() - $lastTick) >= self::TICK_INTERVAL_SECONDS) {
                $lastTick = time();
                $tick();
            }
        } while ($inFlight !== [] || $queued !== []);

        curl_multi_close($multi);

        // Any request that somehow never reported (defensive — a handle lost
        // between add and info_read) comes back as a transport failure rather
        // than a missing key the caller would have to guard against.
        foreach ($requests as $key => $_) {
            $results[$key] ??= ['status' => 0, 'contentType' => '', 'raw' => '', 'error' => 'request produced no response'];
        }

        return $results;
    }
}
